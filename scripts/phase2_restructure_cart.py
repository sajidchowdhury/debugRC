#!/usr/bin/env python3
"""
STYLE-PARITY Phase 2+3 — Restructure cart.blade.php HTML to match legacy.

Replaces lines 29–572 of cart.blade.php (the entire <div id="salesCartApp">
content block) with legacy-faithful HTML that uses the .sales-create-app,
.sales-panel, .sales-customer-due, .sales-stock-banner, .sales-price-band*,
.sales-entry-toolbar*, .sales-cart-dock* class families defined in
/assets/css/sales-pos.css.

Per audit decisions:
  A (hybrid) — keep Select2 (Laravel JS depends on it) but apply
               .sales-search-input class for the legacy 48px indigo look.
  B          — move Summary + Validation + Availability cards from the
               right aside to BELOW the cart table (legacy has no aside).
  C          — replace R13 inline-styled slider with .sales-price-band* classes.
  D          — use legacy "New Sale" header text + single "Today" button.

ALL Laravel JS-dependent element IDs preserved verbatim.
No JS changes required (except adding #cartDock to the workspace toggle).
"""

import os
import sys
from pathlib import Path

CART_BLADE = Path("/home/z/my-project/debugRC/laravel/resources/views/admin/sales/cart.blade.php")

# ---- Read existing file ----
lines = CART_BLADE.read_text(encoding="utf-8").splitlines(keepends=True)
print(f"Read {len(lines)} lines from {CART_BLADE.name}")

# Lines 1-28 are @extends, @section, @php block, end-php, blank line.
# Lines 29-572 are the <div id="salesCartApp">...</div> content block we replace.
# Line 573 is @endsection.
# Lines 574+ are the @push blocks (head_meta, css, scripts) — keep unchanged.

# Sanity-check the boundaries:
assert lines[0].startswith("@extends"), f"Line 1 mismatch: {lines[0]!r}"
assert "@section('content')" in lines[2], f"Line 3 mismatch: {lines[2]!r}"
assert 'id="salesCartApp"' in lines[28], f"Line 29 mismatch: {lines[28]!r}"
assert lines[572].strip() == "@endsection", f"Line 573 mismatch: {lines[572]!r}"
print("Boundary checks passed.")

# ---- New HTML block (replaces lines 29-572, i.e. indices 28-571) ----
NEW_HTML = '''{{--
  ============================================================
  STYLE-PARITY Phase 2+3 (2026-07-22): Full legacy-faithful restructure.
  Replaced Bootstrap .card markup with legacy .sales-panel / .sales-cart-dock
  class families defined in /assets/css/sales-pos.css.

  Per audit decisions (see docs/STYLE_PARITY_AUDIT_PHASE0.md §9):
    A (hybrid) — keep Select2 (Laravel JS depends on it) but apply
                 .sales-search-input class for the legacy 48px indigo look.
                 Full A2 (text-input + .sales-suggest-list) deferred — would
                 require porting sales-create.js autocomplete logic.
    B          — move Summary + Validation + Availability cards from the
                 right aside to BELOW the cart table (legacy has no aside).
    C          — replace R13 inline-styled slider with .sales-price-band* classes.
    D          — use legacy "New Sale" header text + single "Today" button.

  ALL Laravel JS-dependent element IDs preserved verbatim (see audit §4
  for the full ID inventory). The restructure is HTML+CSS only — no JS
  changes required except adding #cartDock to the workspace-show/hide
  toggle (handled in a separate small JS patch below the @push('scripts')
  block — search for "STYLE-PARITY Phase 2" in the JS section).
  ============================================================
--}}
<div id="sales-create-app" class="sales-create-app">
<div class="container-fluid py-2" id="salesCartApp"
     data-branch-id="{{ (int) $branchId }}"
     data-customer-id="{{ $selectedCustomerId ?? '' }}">

    {{-- ===================== HERO HEADER (Decision D1: legacy-faithful) ===================== --}}
    <header class="sales-create-header">
        <div>
            <h1 class="sales-create-title">New Sale</h1>
            <p class="sales-create-sub">Fast billing · multiple customers · live stock</p>
        </div>
        <a href="{{ route('admin.sales-invoices.index') }}" class="btn btn-light btn-sm sales-header-btn">
            <i class="fas fa-list"></i> Today
        </a>
    </header>

    {{-- ===================== R11: MULTI-CART TABS DOCK (legacy-faithful .sales-cart-dock*) ===================== --}}
    {{--
      One pill per open customer-cart. Clicking a pill switches the active
      cart (no page reload). The × button clears that customer's cart and
      removes the tab. Mirrors Legacy `#draft-tabs` in sales/create.php
      (L144–163) + sales-create.js::createOrSwitchTab / closeTab /
      restoreSessionCarts (L657–803).
    --}}
    <section id="draftTabsCard" class="sales-cart-dock mt-3 @if (empty($selectedCustomerId)) d-none @endif">
        <div class="sales-cart-dock-head">
            <div>
                <strong><i class="fas fa-layer-group me-1"></i> Carts</strong>
                <span class="text-muted small ms-1">— switch customers without losing items</span>
                <span id="draftTabsCount" class="badge bg-light text-secondary border ms-2">0 carts</span>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm" id="btnFocusCustomer">
                <i class="fas fa-plus"></i> New customer
            </button>
        </div>
        <div class="sales-cart-tabs-wrap">
            <ul class="nav sales-cart-tabs" id="draftTabs" role="tablist"></ul>
        </div>
        <div id="draftTabsEmpty" class="small text-muted py-1 px-3 pb-2">
            <i class="fas fa-info-circle me-1"></i>
            No open carts. Pick a customer below to start a new one.
        </div>
    </section>

    {{-- ===================== EMPTY STATE (no customer) ===================== --}}
    <div id="emptyState" class="sales-panel mt-3 @if (!empty($selectedCustomerId)) d-none @endif">
        <div class="sales-panel-body text-center py-5">
            <div class="display-6 text-muted mb-3">
                <i class="fas fa-cart-arrow-down"></i>
            </div>
            <h4 class="text-muted">Select a customer to start building an invoice.</h4>
            <p class="text-muted mb-0">The cart is auto-saved per salesman + customer until you finalize the invoice.</p>
        </div>
    </div>

    {{-- ===================== MAIN TWO-COLUMN WORKSPACE ===================== --}}
    {{-- Legacy layout: col-xl-4 (Customer) + col-xl-8 (Product entry), side-by-side --}}
    <div id="workspace" class="row g-3 mt-1 @if (empty($selectedCustomerId)) d-none @endif">

        {{-- ============== LEFT: Customer panel (col-xl-4, legacy-faithful .sales-panel) ============== --}}
        <div class="col-12 col-xl-4">
            <section class="sales-panel">
                <div class="sales-panel-head">
                    <i class="fas fa-user-tie"></i>
                    <span>Customer</span>
                </div>
                <div class="sales-panel-body">
                    <label class="form-label small text-muted mb-1" for="customerSelect" id="customerSearchLabel">
                        Search name, shop or mobile
                    </label>
                    <div class="sales-customer-picker">
                        <div class="position-relative flex-grow-1">
                            {{-- HYBRID Decision A: keep Select2 (Laravel JS depends on it) but apply
                                 .sales-search-input for the legacy 48px indigo look. Full A2
                                 (text-input + suggest-list) deferred — would require porting
                                 sales-create.js autocomplete logic. --}}
                            <select id="customerSelect" class="form-select select2 sales-search-input" style="width:100%;">
                                <option value="">— Select a customer —</option>
                                @if (!empty($selectedCustomer))
                                    <option value="{{ $selectedCustomer->id }}" selected>
                                        {{ $selectedCustomer->customer_name }}
                                        @if (!empty($selectedCustomer->customer_code)) [{{ $selectedCustomer->customer_code }}] @endif
                                    </option>
                                @endif
                            </select>
                            <div id="customerSuggestions" class="sales-suggest-list"></div>
                        </div>
                        <button type="button" class="btn btn-outline-secondary sales-change-customer" id="btnChangeCustomer" title="Change customer">
                            Change
                        </button>
                        <button type="button" id="btnLoadCart" class="btn btn-primary sales-change-customer" title="Load saved cart for this customer">
                            <i class="fas fa-sync me-1"></i> Load
                        </button>
                    </div>

                    {{-- R15: Customer recents chips --}}
                    {{--
                      Ported from Legacy #customerRecents in
                      legacy/app/views/sales/create.php (L47) + sales.js
                      `rememberCustomerRecent` / `renderCustomerRecents`
                      (L1306–1354). Stores the last 5 picked customers in
                      localStorage and renders them as click-to-pick chips
                      beneath the customer Select2.
                    --}}
                    <div id="customerRecentsRow" class="mt-2 d-none">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="small text-muted text-nowrap">
                                <i class="fas fa-clock-rotate-left me-1 text-primary"></i>Recent:
                            </span>
                            <div id="customerRecents" class="sales-recents d-flex flex-wrap gap-1"></div>
                        </div>
                    </div>

                    <div class="row g-2 mt-2 align-items-end">
                        <div class="col-12 d-flex gap-2">
                            <a href="{{ route('admin.sales.cart') }}" class="btn btn-outline-secondary btn-sm" title="Reset cart page">
                                <i class="fas fa-rotate-left me-1"></i> Reset
                            </a>
                        </div>
                    </div>
                </div>

                {{-- R14: Live credit snapshot — restyled to .sales-customer-due (dark slate box) per Decision B2.
                     All R14 JS-dependent IDs preserved (cdCreditLimit, cdCurrentDue, cdDueLeft,
                     cdCartSubtotal, cdProjectedBalance, cdStatus, cdStatusText, btnRefreshCredit). --}}
                {{--
                  Ported from Legacy #customerDetailsPanel in
                  legacy/app/views/sales/create.php (L72–80). Shows the
                  customer's credit_limit, current_due, and due_left inline
                  so the cashier can see at a glance whether adding more
                  items will breach the limit. Mirrors Legacy's
                  disp_limit / disp_due / disp_left layout (L192–207 in
                  sales-pos.css — .sales-customer-due dark slate box).

                  Beyond Legacy parity, R14 also surfaces cart subtotal +
                  projected new balance (audit gap §6.1 #6 — prevents
                  wasted cart-building). Data fetched via R14 endpoint
                  GET /admin/sales/cart/customer-details?customer_id=...
                  (throttled 60/min). Panel re-renders on every cart
                  mutation using cached snapshot + latest subtotal.
                --}}
                <div id="customerDetailsPanel" class="sales-customer-due d-none">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="small">
                            <i class="fas fa-wallet me-1"></i>
                            <strong>Credit snapshot</strong>
                            <span class="opacity-75 ms-1">— live from ledger</span>
                        </span>
                        <button type="button" id="btnRefreshCredit" class="btn btn-sm btn-link text-decoration-none p-0 m-0 text-white"
                                title="Re-fetch from ledger (in case of recent payments)">
                            <i class="fas fa-rotate me-1"></i><span class="small">Refresh</span>
                        </button>
                    </div>
                    <div class="due-row"><span>Credit limit</span><strong id="cdCreditLimit">—</strong></div>
                    <div class="due-row"><span>Current due</span><strong id="cdCurrentDue">—</strong></div>
                    <div class="due-row highlight"><span>Balance left</span><strong id="cdDueLeft">—</strong></div>
                    <div class="due-row"><span>Cart subtotal</span><strong id="cdCartSubtotal">—</strong></div>
                    <div class="due-row highlight mt-1 pt-1" style="border-top:1px solid rgba(255,255,255,0.15);">
                        <span>Projected new balance <small class="opacity-75">(due + cart)</small></span>
                        <strong id="cdProjectedBalance">—</strong>
                    </div>
                    <div id="cdStatusRow" class="mt-2">
                        <span id="cdStatus" class="badge bg-secondary">—</span>
                        <span id="cdStatusText" class="ms-1 small"></span>
                    </div>
                </div>
            </section>
        </div>

        {{-- ============== RIGHT: Product entry panel (col-xl-8, legacy-faithful .sales-panel) ============== --}}
        <div class="col-12 col-xl-8">
            <section class="sales-panel sales-panel-product">
                <div class="sales-panel-head">
                    <i class="fas fa-barcode"></i>
                    <span>Add products</span>
                </div>
                <div class="sales-panel-body">
                    <label class="form-label small text-muted mb-1" for="addProduct">Product name or code</label>
                    <div class="position-relative mb-3">
                        {{-- HYBRID Decision A: Select2 + .sales-search-input class.
                             R10/R15+ simplified: a single Select2 product search box
                             doubles as the barcode scanner entry. Barcode scanners
                             act as HID keyboards — they "type" the code rapidly
                             and end with Enter. Select2's AJAX debounce (250 ms)
                             is long enough to capture the full scan, and
                             `selectOnClose: true` (set in the JS below) makes
                             Enter pick the first highlighted match. If no name
                             match is found, the user's typed term is treated as
                             a product_code and a fallback exact-match lookup is
                             fired against the R1 productByCode endpoint. --}}
                        <select id="addProduct" class="form-select select2 sales-search-input" style="width:100%;">
                            <option value="">— Type name / scan code —</option>
                        </select>
                        <div id="productSuggestions" class="sales-suggest-list"></div>
                    </div>

                    {{-- Stock availability banner (legacy-faithful .sales-stock-banner) --}}
                    {{--
                      Ported from Legacy #BranchStock in legacy/app/views/sales/create.php
                      (L99). Teal-gradient banner showing the total available stock
                      for the currently-selected product across all warehouses in
                      the active branch. Mirrors .sales-stock-banner (L209–247
                      in sales-pos.css).
                    --}}
                    <div id="BranchStock" class="sales-stock-banner d-none">
                        <div class="stock-banner-inner">
                            <div class="stock-stat">
                                <span class="stock-label">Available (branch)</span>
                                <span class="stock-value" id="addAvailTotal">—</span>
                            </div>
                            <span class="text-white-50 small align-self-center">Warehouse breakdown appears in the Availability panel below.</span>
                        </div>
                    </div>

                    {{-- R13: Price-range slider (Decision C2: use .sales-price-band* classes from sales-pos.css) --}}
                    {{--
                      Ported from Legacy #priceRangePanel in
                      legacy/app/views/sales/create.php (L101–121) +
                      sales-create.js::updatePriceBandUi (L129–187).

                      A visual band showing min / default / max rates for
                      the currently-selected product, with a thumb that
                      tracks the live #addRate value. Status text turns
                      green (in range) / amber (near minimum) / red (out
                      of range). The "Use default" button snaps the rate
                      back to default_rate.

                      Phase 2+3 change: replaced inline styles + Bootstrap
                      utilities with .sales-price-band* classes from
                      sales-pos.css (L249–402). All element IDs preserved
                      so sales-create.js::updatePriceBandUi (ported to
                      Laravel JS) works unchanged.
                    --}}
                    <div id="priceRangePanel" class="sales-price-band d-none" aria-live="polite">
                        <div class="sales-price-band-head">
                            <span class="sales-price-band-title"><i class="fas fa-tags"></i> Selling range</span>
                            <button type="button" class="btn btn-sm btn-outline-primary sales-use-default-btn" id="btnUseDefaultRate" title="Set rate to default">
                                Use default
                            </button>
                        </div>
                        <div class="sales-price-band-labels">
                            <span>Min <b id="priceBandMin">0</b></span>
                            <span class="sales-price-band-default">Default <b id="priceBandDefault">0</b></span>
                            <span>Max <b id="priceBandMax">0</b></span>
                        </div>
                        <div class="sales-price-band-track-wrap">
                            <div class="sales-price-band-track">
                                <div class="sales-price-band-fill" id="priceBandFill"></div>
                                <div class="sales-price-band-default-mark" id="priceBandDefaultMark" title="Default price"></div>
                                <div class="sales-price-band-thumb" id="priceBandThumb"></div>
                            </div>
                        </div>
                        <div id="priceRangeStatus" class="sales-price-band-status sales-price-ok">Rate is within allowed range</div>
                    </div>

                    {{-- Rate / Qty / Add toolbar (legacy-faithful .sales-entry-toolbar*) --}}
                    {{--
                      Mirrors Legacy .sales-entry-toolbar (L432–508 in sales-pos.css):
                      flex row with Rate (flex-grow), Qty (5.5rem fixed), and Add
                      button (48px tall, indigo bg). All inputs use .sales-entry-input
                      for consistent 48px height + 10px radius + indigo focus ring.
                      On <575.98px screens, the toolbar collapses to a 2-row grid
                      per the media query at L652–687.
                    --}}
                    <div class="sales-entry-toolbar">
                        <div class="sales-entry-group sales-entry-rate">
                            <label class="sales-entry-label" for="addRate">Rate (৳)</label>
                            <input type="number" step="0.01" id="addRate" class="form-control sales-entry-input" placeholder="0.00" inputmode="decimal" min="0">
                            <div id="rateHint" class="form-text small text-muted mt-1">&nbsp;</div>
                        </div>
                        <div class="sales-entry-group sales-entry-qty">
                            <label class="sales-entry-label" for="addQty">Qty</label>
                            <input type="number" step="0.001" id="addQty" class="form-control sales-entry-input text-center" value="1" inputmode="decimal" min="0.001">
                        </div>
                        <button type="button" id="btnAddToCart" class="btn btn-primary sales-add-btn">
                            <i class="fas fa-cart-plus"></i>
                            <span class="sales-add-text">Add</span>
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </div>

    {{-- ===================== CART ITEMS TABLE (full-width below workspace, Decision B2) ===================== --}}
    {{--
      Legacy has the cart table inside .sales-cart-dock below the workspace
      form. Per Decision B2, we move Summary + Validation + Availability
      cards from the right aside to BELOW the cart table — no right aside.
      The #cartDock wrapper is added so the existing workspace-toggle JS
      can show/hide both #workspace and #cartDock together (JS patch
      applied below in @push('scripts')).
    --}}
    <div id="cartDock" class="@if (empty($selectedCustomerId)) d-none @endif">
        <section class="sales-panel mt-3">
            <div class="sales-panel-head d-flex align-items-center justify-content-between">
                <span>
                    <i class="fas fa-list me-1"></i>
                    <strong>Cart Items</strong>
                    <span id="itemsCountBadge" class="badge bg-secondary ms-2">0</span>
                </span>
                <span class="text-muted small d-none d-md-inline">Inline edits auto-save (300ms debounce)</span>
            </div>
            <div class="sales-panel-body p-0">
                {{-- R17: desktop table view (hidden on <md screens) --}}
                <div class="sales-cart-desktop table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:220px;">Product</th>
                                <th style="width:110px;">Qty</th>
                                <th style="width:140px;">Rate</th>
                                <th class="text-end" style="width:120px;">Total</th>
                                <th class="text-end" style="width:110px;">Available</th>
                                <th class="text-center" style="width:80px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="cartItemsBody">
                            {{-- rendered by JS --}}
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="3" class="text-end">Subtotal</th>
                                <th class="text-end" id="cartSubtotalCell">0.00</th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                {{-- R17: mobile card view (shown on <md screens). Renders
                     one .sales-cart-line card per cart item with large
                     tap targets + a swipe-to-delete gesture (touchstart
                     → touchend delta < -80px triggers removeItem). --}}
                <div class="sales-cart-mobile" id="cartItemsMobile">
                    {{-- rendered by JS --}}
                </div>
                <div id="cartEmptyRow" class="text-center text-muted py-4 border-top">
                    <i class="fas fa-inbox me-1"></i> Cart is empty — add a product above.
                </div>
            </div>
        </section>

        {{-- ---------- Cart actions row (Clear/Hold/Validate/Finalize) ---------- --}}
        <section class="sales-panel mt-3">
            <div class="sales-panel-body d-flex flex-wrap gap-2 align-items-center">
                <button type="button" id="btnClear" class="btn btn-outline-danger">
                    <i class="fas fa-trash me-1"></i> Clear Cart
                </button>
                <button type="button" id="btnSoftHold" class="btn btn-outline-warning">
                    <i class="fas fa-pause-circle me-1"></i>
                    <span id="softHoldBtnLabel">Soft Hold</span>
                </button>
                <button type="button" id="btnValidate" class="btn btn-outline-info">
                    <i class="fas fa-check-double me-1"></i> Validate Cart
                </button>
                <button type="button" id="btnFinalize"
                        class="btn btn-success btn-finalize ms-auto"
                        data-bs-toggle="tooltip" data-bs-placement="top"
                        title="Create a draft sales invoice from this cart (GL posted)">
                    <i class="fas fa-file-invoice-dollar me-1"></i> Finalize Invoice
                </button>
            </div>
        </section>

        {{-- ---------- Three-column row: Summary + Validation + Availability (Decision B2: moved from right aside) ---------- --}}
        <div class="row g-3 mt-1">
            {{-- Summary --}}
            <div class="col-12 col-lg-4">
                <section class="sales-panel">
                    <div class="sales-panel-head">
                        <i class="fas fa-receipt"></i>
                        <span>Summary</span>
                    </div>
                    <div class="sales-panel-body">
                        <dl class="row mb-0 small">
                            <dt class="col-5 text-muted fw-normal">Customer</dt>
                            <dd class="col-7 text-end fw-semibold" id="sumCustomer">—</dd>

                            <dt class="col-5 text-muted fw-normal">Branch</dt>
                            <dd class="col-7 text-end" id="sumBranch">{{ $branchName }}</dd>

                            <dt class="col-5 text-muted fw-normal">Items</dt>
                            <dd class="col-7 text-end" id="sumItems">0</dd>

                            <dt class="col-5 text-muted fw-normal">Soft-hold</dt>
                            <dd class="col-7 text-end">
                                <span id="sumSoftHold" class="badge bg-secondary">No</span>
                            </dd>
                        </dl>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Subtotal</span>
                            <span id="sumSubtotal" class="fs-4 fw-bold text-primary">0.00</span>
                        </div>
                    </div>
                </section>
            </div>

            {{-- Validation --}}
            <div class="col-12 col-lg-4">
                <section class="sales-panel">
                    <div class="sales-panel-head d-flex align-items-center justify-content-between">
                        <span>
                            <i class="fas fa-clipboard-check me-1"></i>
                            <span>Validation</span>
                        </span>
                        <span id="validationBadge" class="badge bg-secondary">Not checked</span>
                    </div>
                    <div class="sales-panel-body">
                        <p id="validationMessage" class="small text-muted mb-2">Click "Validate Cart" to run the hard gate.</p>

                        <div id="stockErrorsBox" class="d-none">
                            <div class="small fw-semibold text-danger mb-1">
                                <i class="fas fa-triangle-exclamation me-1"></i>Stock Shortfalls
                            </div>
                            <ul id="stockErrorsList" class="list-unstyled small mb-2 ps-3"></ul>
                        </div>

                        <div id="rateErrorsBox" class="d-none">
                            <div class="small fw-semibold text-warning mb-1">
                                <i class="fas fa-tag me-1"></i>Rate Out of Range
                            </div>
                            <ul id="rateErrorsList" class="list-unstyled small mb-0 ps-3"></ul>
                        </div>
                    </div>
                </section>
            </div>

            {{-- Availability --}}
            <div class="col-12 col-lg-4">
                <section class="sales-panel">
                    <div class="sales-panel-head d-flex align-items-center justify-content-between">
                        <span>
                            <i class="fas fa-warehouse me-1"></i>
                            <span>Availability</span>
                        </span>
                        <span id="availProductBadge" class="badge bg-secondary">No product</span>
                    </div>
                    <div class="sales-panel-body">
                        <div id="availEmpty" class="text-center text-muted small py-3">
                            <i class="fas fa-magnifying-glass me-1"></i> Select a product in "Add Product" to see per-warehouse availability.
                        </div>
                        <div id="availTableWrap" class="d-none">
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Warehouse</th>
                                            <th class="text-end">Physical</th>
                                            <th class="text-end">Pipeline</th>
                                            <th class="text-end">Available</th>
                                        </tr>
                                    </thead>
                                    <tbody id="availTableBody"></tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <th>Total</th>
                                            <th class="text-end" id="availTotalPhysical">0.00</th>
                                            <th class="text-end" id="availTotalPipeline">0.00</th>
                                            <th class="text-end" id="availTotalAvailable">0.00</th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    {{-- ============ R16: STICKY BOTTOM BAR ============ --}}
    {{--
      Ported from Legacy #posStickyBar in legacy/app/views/sales/create.php
      (L166–173) + sales.js::updatePosStickyBar / initPosStickyBar
      (L1356–1380).

      A fixed-position bottom bar that is always visible while the
      cashier has a cart open. Shows live item count + grand total
      and a Finalize button — so on long carts the cashier never
      has to scroll back to the top of the page to finalize. The
      bar mirrors the existing #btnFinalize button (same handler,
      same idempotency-token flow, same credit-check gate).

      Visibility rules:
        - Empty cart or no customer → bar hidden, button disabled
        - Cart with items but not validated → bar visible, button
          disabled (clicking shows a "validate first" toast)
        - Cart with items + valid → bar visible, button enabled

      The bar sits at z-index 1040 (below SweetAlert's 10,000+)
      and respects the iOS safe-area inset so it doesn't get
      clipped on notched devices. The page itself gets extra
      padding-bottom via .sales-pos-page so the bar never covers
      the last cart row.
    --}}
    <div class="sales-pos-sticky-bar" id="posStickyBar">
        <div class="d-flex justify-content-between align-items-center gap-2 w-100">
            <div class="sticky-summary" id="posStickySummary">
                <span class="text-muted small">No active cart</span>
            </div>
            <button type="button" class="btn btn-success btn-finalize flex-shrink-0" id="posStickyFinalize" disabled
                    title="Create a draft sales invoice from this cart">
                <i class="fas fa-check-circle me-1"></i> Finalize
            </button>
        </div>
    </div>
</div>
</div>
'''

# ---- Splice the new HTML into the file ----
# Keep lines 1-28 (indices 0-27), replace lines 29-572 (indices 28-571),
# keep lines 573+ (index 572+) unchanged.
new_lines = lines[:28] + [NEW_HTML] + lines[572:]
CART_BLADE.write_text("".join(new_lines), encoding="utf-8")
print(f"Wrote {len(new_lines)} logical lines back to {CART_BLADE.name}")

# ---- Verify the result ----
result = CART_BLADE.read_text(encoding="utf-8").splitlines(keepends=True)
print(f"Result file has {len(result)} lines.")

# Quick sanity checks
text = "".join(result)
checks = [
    ("@extends('layouts.admin')", "first line"),
    ("@section('content')", "section opener"),
    ("@endsection", "section closer"),
    ('id="sales-create-app"', "legacy wrapper"),
    ('class="sales-create-app"', "legacy wrapper class"),
    ('class="sales-create-header"', "legacy header"),
    ("New Sale", "legacy header text"),
    ('id="customerSelect"', "customer Select2 preserved"),
    ('id="addProduct"', "product Select2 preserved"),
    ('class="sales-search-input"', "Decision A: Select2 + legacy class"),
    ('class="sales-panel"', "Decision B: legacy panel class"),
    ('class="sales-customer-due', "Decision B: legacy credit box"),
    ('class="sales-stock-banner', "Decision B: legacy stock banner"),
    ('class="sales-price-band', "Decision C: legacy price band"),
    ('class="sales-entry-toolbar"', "Decision D: legacy entry toolbar"),
    ('class="sales-cart-dock', "legacy multi-cart dock"),
    ('id="cartDock"', "new cart dock wrapper"),
    ('id="btnFinalize"', "finalize button preserved"),
    ('id="posStickyBar"', "sticky bar preserved"),
    ('id="cdCreditLimit"', "R14 credit limit ID preserved"),
    ('id="cdProjectedBalance"', "R14 projected balance ID preserved"),
    ('id="sumSubtotal"', "summary subtotal preserved"),
    ('id="availTableBody"', "availability table preserved"),
    ('id="priceBandThumb"', "R13 slider thumb preserved"),
    ("@push('head_meta')", "head_meta push preserved"),
    ("@push('css')", "css push preserved"),
    ("@push('scripts')", "scripts push preserved"),
]
missing = []
for needle, label in checks:
    if needle not in text:
        missing.append((label, needle))
if missing:
    print("\n!!! MISSING CHECKS:")
    for label, needle in missing:
        print(f"  - {label}: {needle!r}")
    sys.exit(1)
else:
    print("\nAll sanity checks passed.")

# Count occurrences of key markers
import re
push_count = len(re.findall(r"^@push\(", text, re.MULTILINE))
endpush_count = len(re.findall(r"^@endpush", text, re.MULTILINE))
section_count = len(re.findall(r"^@section\(", text, re.MULTILINE))
endsection_count = len(re.findall(r"^@endsection", text, re.MULTILINE))
print(f"\nDirective balance:")
print(f"  @push:     {push_count}")
print(f"  @endpush:  {endpush_count}")
print(f"  @section:  {section_count}")
print(f"  @endsection: {endsection_count}")
if push_count != endpush_count:
    print("  !!! @push/@endpush mismatch")
    sys.exit(1)
if section_count != endsection_count:
    print("  !!! @section/@endsection mismatch")
    sys.exit(1)
print("  All balanced.")
