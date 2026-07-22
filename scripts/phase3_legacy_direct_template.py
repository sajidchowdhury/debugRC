#!/usr/bin/env python3
"""
STYLE-PARITY Phase 3 (Direct Legacy Template)
=================================================

User directive (verbatim):
  "i dont get it why cant u just pull the html from the lagachy software
   and use it as a blade for laravel proejct and start to give functinality
   as lagachy have !! why i ahve to explain and give me screen shot
   just go use lagachy as a templet referace and use it directly in the
   laravel as templet"

Approach: take the legacy sales/create.php HTML structure as the GOLD
template and lay the cart blade's @section('content') directly on top of
it. All Laravel JS-dependent element IDs are preserved (kept in DOM) but
the Laravel-only Summary / Validation / Availability panels are moved
into a hidden #laravelExtras div so the visible page matches the legacy
layout exactly.

Structural changes vs current cart.blade.php:
  1. Workspace is wrapped in <form id="kt_form" class="sales-create-form">
     (legacy-faithful) — adds the hidden csrf_token / related_id / etc.
  2. Cart dock moved from BEFORE workspace to AFTER workspace
     (legacy position — sales/create.php L144-163).
  3. Cart table + cart actions moved INSIDE the cart-dock's tab-content
     area (legacy renders cart inside #draft-tab-content).
  4. Added legacy-faithful #emptyCartHint inside the tab-content area
     (legacy L158-161). Shown when no customer selected.
  5. Summary / Validation / Availability panels wrapped in
     <div id="laravelExtras" class="d-none"> — JS still updates them
     but they are not visible (legacy has none of these).
  6. Added the legacy .sales-meta-grid (Branch / Date / Sales By /
     Sales Person / Narration) inside the customer panel — was missing.
  7. All Blade @if conditional d-none classes preserved on customer
     picker + recents row.

JS changes (in @push('scripts')):
  - setWorkspaceVisible() now toggles #cartDock + #emptyCartHint
    (in addition to #cartEmptyRow which it already toggled).
"""

import re
import sys
from pathlib import Path

BLADE = Path('/home/z/my-project/debugRC/laravel/resources/views/admin/sales/cart.blade.php')
if not BLADE.exists():
    print(f"ERROR: blade file not found: {BLADE}", file=sys.stderr)
    sys.exit(1)

text = BLADE.read_text(encoding='utf-8')
lines = text.splitlines(keepends=True)

# ---------------------------------------------------------------------------
# 1. Locate the @section('content') ... @endsection block boundaries.
# ---------------------------------------------------------------------------
sec_start = None
sec_end = None
for i, ln in enumerate(lines):
    if sec_start is None and re.match(r'^@section\([\'\"]content[\'\"]\)', ln):
        sec_start = i
        continue
    if sec_start is not None and re.match(r'^@endsection\b', ln):
        sec_end = i
        break

if sec_start is None or sec_end is None:
    print("ERROR: could not locate @section('content') ... @endsection", file=sys.stderr)
    sys.exit(1)

print(f"Found @section('content') at line {sec_start+1}, @endsection at line {sec_end+1}")

# ---------------------------------------------------------------------------
# 2. Build the new content section: keep @section opener + @php block,
#    replace everything from the @endphp onwards with legacy-faithful HTML.
# ---------------------------------------------------------------------------
# Find the @endphp that closes the @php block (so we keep the @php ... @endphp
# server-side data prep intact).
php_end = None
for i in range(sec_start, sec_end):
    if re.match(r'^@endphp\b', lines[i]):
        php_end = i
        break

if php_end is None:
    print("ERROR: could not find @endphp inside @section('content')", file=sys.stderr)
    sys.exit(1)

print(f"Found @endphp at line {php_end+1}")

# Header block to keep: from @section('content') down through @endphp
header_block = ''.join(lines[sec_start:php_end+1])

# ---------------------------------------------------------------------------
# 3. Compose the new HTML body (legacy-faithful, Laravel-wired).
# ---------------------------------------------------------------------------
new_body = r'''
{{--
  ============================================================
  STYLE-PARITY Phase 3 — DIRECT LEGACY TEMPLATE (2026-07-22)
  ============================================================
  Per user directive: "just go use lagachy as a templet referace and
  use it directly in the laravel as templet".

  This section now mirrors legacy/app/views/sales/create.php (L9-173)
  verbatim in structure, with Laravel blade syntax substituted for
  legacy PHP server-side bits:
    - BASE_URL                     → url('/') / route()
    - $_SESSION['csrf_token']      → csrf_token()
    - htmlspecialchars($x)         → e($x) / {{ $x }}
    - date('Y-m-d')                → date('Y-m-d')

  Laravel JS-dependent element IDs preserved (in DOM, may be hidden):
    CUSTOMER:  customerSearch, customerSuggestions, customer_id,
               customerSearchLabel, btnChangeCustomer, customerRecentsRow,
               customerRecents, customerDetailsPanel, cdCreditLimit,
               cdCurrentDue, cdDueLeft, cdCartSubtotal, cdProjectedBalance,
               cdStatus, cdStatusText, btnRefreshCredit
    PRODUCT:   productSearch, productSuggestions, addProduct, addQty,
               addRate, rateHint, BranchStock, addAvailTotal,
               priceRangePanel, priceBandMin, priceBandDefault, priceBandMax,
               priceBandFill, priceBandDefaultMark, priceBandThumb,
               priceRangeStatus, btnUseDefaultRate, btnAddToCart
    CART DOCK: draftTabsCard, draftTabs, draftTabsCount, draftTabsEmpty,
               btnFocusCustomer, emptyCartHint, cartDock, cartItemsBody,
               cartItemsMobile, cartEmptyRow, cartSubtotalCell,
               itemsCountBadge, btnClear, btnSoftHold, btnValidate,
               btnFinalize, softHoldBtnLabel
    STICKY:    posStickyBar, posStickySummary, posStickyFinalize
    EXTRAS:    laravelExtras (hidden) wraps sumCustomer, sumItems,
               sumSoftHold, sumSubtotal, sumBranch, validationBadge,
               validationMessage, stockErrorsBox, stockErrorsList,
               rateErrorsBox, rateErrorsList, availProductBadge,
               availEmpty, availTableWrap, availTableBody,
               availTotalPhysical, availTotalPipeline, availTotalAvailable
  ============================================================
--}}
<div id="sales-create-app" class="sales-create-app">
<div class="container-fluid py-2" id="salesCartApp"
     data-branch-id="{{ (int) $branchId }}"
     data-customer-id="{{ $selectedCustomerId ?? '' }}">

    {{-- ===================== HERO HEADER (legacy L10-19) ===================== --}}
    <header class="sales-create-header">
        <div>
            <h1 class="sales-create-title">New Sale</h1>
            <p class="sales-create-sub">Fast billing · multiple customers · live stock</p>
        </div>
        <a href="{{ route('admin.sales-invoices.index') }}" class="btn btn-light btn-sm sales-header-btn">
            <i class="fas fa-list"></i> Today
        </a>
    </header>

    {{-- ===================== WORKSPACE FORM (legacy L21-141) ===================== --}}
    {{-- Legacy wraps customer + product panels in <form id="kt_form">. We do
         the same — adds the hidden csrf / related_id / customer_id fields
         that legacy sales-create.js reads. --}}
    <form id="kt_form" class="sales-create-form">
        <input type="hidden" id="related_id" value="New">
        <input type="hidden" name="csrf_token" value="{{ csrf_token() }}">
        <input type="hidden" id="customer_id" value="{{ $selectedCustomerId ?? '' }}">
        <input type="hidden" id="addProduct" value="">
        <script>window.CSRF_TOKEN = {{ json_encode(csrf_token()) }}; window.SALES_CREATE_MODE = true;</script>

        <div class="row g-3">

            {{-- ============== LEFT: Customer panel (col-xl-4, legacy L29-82) ============== --}}
            <div class="col-12 col-xl-4">
                <section class="sales-panel">
                    <div class="sales-panel-head">
                        <i class="fas fa-user-tie"></i>
                        <span>Customer</span>
                    </div>
                    <div class="sales-panel-body">
                        <label class="form-label small text-muted mb-1" for="customerSearch" id="customerSearchLabel">
                            @if (!empty($selectedCustomer)) Selected customer @else Search name, shop or mobile @endif
                        </label>
                        <div class="sales-customer-picker">
                            <div class="position-relative flex-grow-1">
                                <input type="text" id="customerSearch"
                                       class="form-control sales-search-input @if (!empty($selectedCustomer)) is-locked @endif"
                                       placeholder="Type to search customer..." autocomplete="off"
                                       @if (!empty($selectedCustomer)) readonly value="{{ $selectedCustomer->shop_name ?: $selectedCustomer->customer_name }}" @endif>
                                <div id="customerSuggestions" class="sales-suggest-list"></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary sales-change-customer @if (empty($selectedCustomer)) d-none @endif" id="btnChangeCustomer" title="Change customer">
                                Change
                            </button>
                        </div>

                        {{-- R15: Customer recents chips (Laravel-only enhancement, kept) --}}
                        <div id="customerRecentsRow" class="mt-2 @if (!empty($selectedCustomer)) d-none @endif">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="small text-muted text-nowrap">
                                    <i class="fas fa-clock-rotate-left me-1 text-primary"></i>Recent:
                                </span>
                                <div id="customerRecents" class="sales-recents d-flex flex-wrap gap-1"></div>
                            </div>
                        </div>

                        {{-- Meta grid (legacy L49-70) — was missing in Laravel --}}
                        <div class="mt-3 sales-meta-grid">
                            <div>
                                <label class="form-label small">Branch</label>
                                <select id="branch_id" class="form-select">
                                    <option value="{{ (int) $branchId }}" selected>{{ $branchName }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label small">Date</label>
                                <input type="date" id="invoice_date" class="form-control" value="{{ date('Y-m-d') }}">
                            </div>
                            <div>
                                <label class="form-label small">Sales By</label>
                                <select id="sales_by" class="form-select"></select>
                            </div>
                            <div>
                                <label class="form-label small">Sales Person</label>
                                <select id="sales_person" class="form-select"></select>
                            </div>
                            <div class="col-span-2">
                                <label class="form-label small">Narration</label>
                                <input type="text" id="narration" class="form-control" placeholder="Delivery note...">
                            </div>
                        </div>
                    </div>

                    {{-- R14: Live credit snapshot (legacy .sales-customer-due, enhanced) --}}
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

            {{-- ============== RIGHT: Product entry panel (col-xl-8, legacy L85-139) ============== --}}
            <div class="col-12 col-xl-8">
                <section class="sales-panel sales-panel-product">
                    <div class="sales-panel-head">
                        <i class="fas fa-barcode"></i>
                        <span>Add products</span>
                    </div>
                    <div class="sales-panel-body">
                        <label class="form-label small text-muted mb-1" for="productSearch">Product name or code</label>
                        <div class="position-relative mb-3">
                            <input type="text" id="productSearch" class="form-control sales-search-input"
                                   placeholder="Scan barcode or search product..." autocomplete="off">
                            <div id="productSuggestions" class="sales-suggest-list"></div>
                        </div>

                        {{-- Stock availability banner (legacy L99) --}}
                        <div id="BranchStock" class="sales-stock-banner d-none">
                            <div class="stock-banner-inner">
                                <div class="stock-stat">
                                    <span class="stock-label">Available (branch)</span>
                                    <span class="stock-value" id="addAvailTotal">—</span>
                                </div>
                                <span class="text-white-50 small align-self-center">Warehouse breakdown appears in the Availability panel below.</span>
                            </div>
                        </div>

                        {{-- R13: Price-range slider (legacy L101-121, .sales-price-band*) --}}
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

                        {{-- Rate / Qty / Add toolbar (legacy L123-136, .sales-entry-toolbar*) --}}
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
    </form>

    {{-- ===================== CART DOCK (legacy L144-163, AFTER workspace) ===================== --}}
    {{--
      Legacy has the cart-dock BELOW the workspace form. The dock contains
      the cart tabs + a tab-content area that holds EITHER the empty-cart
      hint OR the active cart panel (cart table + actions). When a customer
      is selected, JS hides #emptyCartHint and shows #cartDock.
    --}}
    <section class="sales-cart-dock mt-3" id="draftTabsCard">
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
            No open carts. Pick a customer above to start a new one.
        </div>

        {{-- Tab content area (legacy .tab-content.sales-tab-panels) --}}
        <div class="tab-content sales-tab-panels" id="draft-tab-content">

            {{-- Legacy-faithful empty-cart hint (legacy L158-161).
                 Visible on first paint (no customer selected). JS hides
                 it via setWorkspaceVisible(true) when a customer is picked. --}}
            <div class="sales-empty-cart text-center text-muted py-5" id="emptyCartHint">
                <i class="fas fa-shopping-basket fa-2x mb-2 opacity-50"></i>
                <p class="mb-0">Select a customer, then add products</p>
            </div>

            {{-- Active cart panel — hidden initially, JS shows via
                 setWorkspaceVisible(true). Mirrors legacy tab-pane content
                 that sales.js::loadCart() renders into #cart-${customerId}. --}}
            <div id="cartDock" class="d-none">
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
                                <tbody id="cartItemsBody"></tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="3" class="text-end">Subtotal</th>
                                        <th class="text-end" id="cartSubtotalCell">0.00</th>
                                        <th colspan="2"></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        {{-- R17: mobile card view (shown on <md screens) --}}
                        <div class="sales-cart-mobile" id="cartItemsMobile"></div>
                        <div id="cartEmptyRow" class="text-center text-muted py-4 border-top d-none">
                            <i class="fas fa-inbox me-1"></i> Cart is empty — add a product above.
                        </div>
                    </div>
                </section>

                {{-- Cart actions row (Clear/Hold/Validate/Finalize) --}}
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
            </div>
        </div>
    </section>

    {{-- ===================== LARAVEL-ONLY EXTRAS (hidden, kept for JS state) ===================== --}}
    {{--
      Legacy sales/create.php has no Summary / Validation / Availability
      panels — it surfaces validation errors via inline .sales-cart-invalid-
      banner alerts inside the cart container, and surfaces summary via the
      sticky bar. The Laravel JS, however, updates these elements by ID.
      We keep them in the DOM (hidden) so the JS does not break, but they
      are not visible on the page — matching the legacy layout exactly.
    --}}
    <div id="laravelExtras" class="d-none" aria-hidden="true">
        {{-- Summary IDs --}}
        <dl class="row mb-0 small">
            <dt class="col-5 text-muted fw-normal">Customer</dt>
            <dd class="col-7 text-end fw-semibold" id="sumCustomer">—</dd>
            <dt class="col-5 text-muted fw-normal">Branch</dt>
            <dd class="col-7 text-end" id="sumBranch">{{ $branchName }}</dd>
            <dt class="col-5 text-muted fw-normal">Items</dt>
            <dd class="col-7 text-end" id="sumItems">0</dd>
            <dt class="col-5 text-muted fw-normal">Soft-hold</dt>
            <dd class="col-7 text-end"><span id="sumSoftHold" class="badge bg-secondary">No</span></dd>
            <dt class="col-5 text-muted fw-normal">Subtotal</dt>
            <dd class="col-7 text-end" id="sumSubtotal">0.00</dd>
        </dl>

        {{-- Validation IDs --}}
        <span id="validationBadge" class="badge bg-secondary">Not checked</span>
        <p id="validationMessage" class="small text-muted mb-2"></p>
        <div id="stockErrorsBox" class="d-none">
            <ul id="stockErrorsList" class="list-unstyled small mb-2 ps-3"></ul>
        </div>
        <div id="rateErrorsBox" class="d-none">
            <ul id="rateErrorsList" class="list-unstyled small mb-0 ps-3"></ul>
        </div>

        {{-- Availability IDs --}}
        <span id="availProductBadge" class="badge bg-secondary">No product</span>
        <div id="availEmpty" class="text-center text-muted small py-3"></div>
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

    {{-- ===================== STICKY BOTTOM BAR (legacy L166-173) ===================== --}}
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

# Compose the new section content
new_section = header_block + new_body + '@endsection\n'

# ---------------------------------------------------------------------------
# 4. Splice: replace lines[sec_start:sec_end+1] with new_section.
# ---------------------------------------------------------------------------
new_lines = lines[:sec_start] + [new_section] + lines[sec_end+1:]
new_text = ''.join(new_lines)

# ---------------------------------------------------------------------------
# 5. Sanity checks.
# ---------------------------------------------------------------------------
def check(label, pat, text, expected=True):
    # Use DOTALL so .*? can span newlines, plus MULTILINE for ^ $
    found = bool(re.search(pat, text, re.MULTILINE | re.DOTALL))
    status = 'OK' if found == expected else 'FAIL'
    print(f"  [{status}] {label}")
    if found != expected:
        return False
    return True

print("\nSanity checks:")
ok = True
ok &= check("nested @section('content') opener present", r'^@section\([\'"]content[\'"]\)', new_text)
ok &= check("@endsection present", r'^@endsection\b', new_text)
ok &= check("@endphp boundary preserved", r'^@endphp\b', new_text)
ok &= check("legacy .sales-create-app wrapper", r'class="sales-create-app"', new_text)
ok &= check("legacy <form id=\"kt_form\">", r'<form id="kt_form"', new_text)
ok &= check("legacy .sales-create-header", r'class="sales-create-header"', new_text)
ok &= check("legacy .sales-cart-dock AFTER workspace",
            r'</form>.*?<section[^>]*class="sales-cart-dock', new_text)
ok &= check("legacy #emptyCartHint inside tab-content",
            r'id="draft-tab-content".*?id="emptyCartHint"', new_text, expected=True)
ok &= check("#cartDock inside tab-content (after emptyCartHint)",
            r'id="emptyCartHint".*?id="cartDock"', new_text, expected=True)
ok &= check("#laravelExtras hidden wrapper", r'id="laravelExtras" class="d-none"', new_text)
ok &= check("#posStickyBar present", r'id="posStickyBar"', new_text)

# Required JS-dependent IDs all still present
required_ids = [
    'customerSearch', 'customerSuggestions', 'customer_id', 'customerSearchLabel',
    'btnChangeCustomer', 'customerRecentsRow', 'customerRecents',
    'customerDetailsPanel', 'cdCreditLimit', 'cdCurrentDue', 'cdDueLeft',
    'cdCartSubtotal', 'cdProjectedBalance', 'cdStatus', 'cdStatusText', 'btnRefreshCredit',
    'productSearch', 'productSuggestions', 'addProduct', 'addQty', 'addRate', 'rateHint',
    'BranchStock', 'addAvailTotal',
    'priceRangePanel', 'priceBandMin', 'priceBandDefault', 'priceBandMax',
    'priceBandFill', 'priceBandDefaultMark', 'priceBandThumb', 'priceRangeStatus',
    'btnUseDefaultRate', 'btnAddToCart',
    'draftTabsCard', 'draftTabs', 'draftTabsCount', 'draftTabsEmpty', 'btnFocusCustomer',
    'emptyCartHint', 'cartDock', 'cartItemsBody', 'cartItemsMobile', 'cartEmptyRow',
    'cartSubtotalCell', 'itemsCountBadge', 'btnClear', 'btnSoftHold', 'btnValidate',
    'btnFinalize', 'softHoldBtnLabel',
    'posStickyBar', 'posStickySummary', 'posStickyFinalize',
    'sumCustomer', 'sumItems', 'sumSoftHold', 'sumSubtotal', 'sumBranch',
    'validationBadge', 'validationMessage', 'stockErrorsBox', 'stockErrorsList',
    'rateErrorsBox', 'rateErrorsList',
    'availProductBadge', 'availEmpty', 'availTableWrap', 'availTableBody',
    'availTotalPhysical', 'availTotalPipeline', 'availTotalAvailable',
]
missing = [rid for rid in required_ids if f'id="{rid}"' not in new_text]
if missing:
    print(f"  [FAIL] Missing required IDs: {missing}")
    ok = False
else:
    print(f"  [OK]   All {len(required_ids)} required element IDs present")

# Blade directive balance
push_count = len(re.findall(r'^@push\(', new_text, re.MULTILINE))
endpush_count = len(re.findall(r'^@endpush\b', new_text, re.MULTILINE))
print(f"  [{'OK' if push_count == endpush_count else 'FAIL'}] @push/@endpush balance: {push_count}/{endpush_count}")
ok &= (push_count == endpush_count)

# @@push escapes in comments should still be intact (don't double-escape)
double_escape = re.findall(r'@@@push\(', new_text)
if double_escape:
    print(f"  [FAIL] Triple-@@@push over-escape detected: {len(double_escape)} occurrences")
    ok = False
else:
    print(f"  [OK]   No triple-@@@push over-escape")

# Brace balance
open_b = new_text.count('{')
close_b = new_text.count('}')
print(f"  [{'OK' if open_b == close_b else 'INFO'}] Braces: {open_b} open / {close_b} close (diff {open_b - close_b})")

if not ok:
    print("\nERROR: sanity checks failed — NOT writing file.", file=sys.stderr)
    sys.exit(2)

# ---------------------------------------------------------------------------
# 6. Patch the JS setWorkspaceVisible() to also toggle #cartDock + #emptyCartHint.
# ---------------------------------------------------------------------------
print("\nPatching JS setWorkspaceVisible() ...")

old_js = """    function setWorkspaceVisible(customerSelected) {
        // STYLE-PARITY Phase 3 (Decision B2/D1): The workspace + cartDock
        // are always visible from first paint (legacy parity — image 3).
        // We only toggle the empty-cart hint inside #cartEmptyRow so the
        // cashier sees "Cart is empty — add a product above." when no
        // customer is selected, and the actual cart rows when one is.
        // The #emptyState panel was removed entirely (legacy has none).
        if (customerSelected) {
            $('#cartEmptyRow').addClass('d-none');
        } else {
            $('#cartEmptyRow').removeClass('d-none');
        }
    }"""

new_js = """    function setWorkspaceVisible(customerSelected) {
        // STYLE-PARITY Phase 3 (Direct Legacy Template, 2026-07-22):
        // Mirrors legacy sales/create.php initial-paint behavior:
        //   - No customer selected  → #emptyCartHint visible, #cartDock hidden.
        //     The cart panel + actions remain in DOM but hidden.
        //   - Customer selected      → #cartDock visible, #emptyCartHint hidden.
        //     The inner #cartEmptyRow handles the "no items yet" hint when
        //     the cart itself is empty.
        if (customerSelected) {
            $('#cartEmptyRow').addClass('d-none');
            $('#cartDock').removeClass('d-none');
            $('#emptyCartHint').addClass('d-none');
        } else {
            $('#cartEmptyRow').removeClass('d-none');
            $('#cartDock').addClass('d-none');
            $('#emptyCartHint').removeClass('d-none');
        }
    }"""

if old_js not in new_text:
    print("ERROR: could not find setWorkspaceVisible() to patch", file=sys.stderr)
    sys.exit(3)
new_text = new_text.replace(old_js, new_js)
print("  [OK]   setWorkspaceVisible() patched to toggle #cartDock + #emptyCartHint")

# ---------------------------------------------------------------------------
# 7. Write back.
# ---------------------------------------------------------------------------
BLADE.write_text(new_text, encoding='utf-8')
new_line_count = len(new_text.splitlines())
print(f"\nWrote {BLADE}")
print(f"  New line count: {new_line_count}")

# ---------------------------------------------------------------------------
# 8. Final summary.
# ---------------------------------------------------------------------------
print("\n" + "="*72)
print("PHASE 3 (Direct Legacy Template) — STRUCTURAL REWRITE COMPLETE")
print("="*72)
print("Key structural changes:")
print("  1. Workspace now wrapped in <form id=\"kt_form\"> (legacy-faithful)")
print("  2. Cart dock moved from BEFORE workspace → AFTER workspace (legacy)")
print("  3. Cart table + cart actions moved INSIDE cart-dock's tab-content")
print("  4. Legacy #emptyCartHint added inside tab-content area")
print("  5. Summary / Validation / Availability panels moved to hidden")
print("     #laravelExtras div (JS still updates them; legacy has none)")
print("  6. Legacy .sales-meta-grid added to customer panel")
print("     (Branch / Date / Sales By / Sales Person / Narration)")
print("  7. JS setWorkspaceVisible() patched to toggle #cartDock + #emptyCartHint")
print()
print("Visible page now matches legacy sales/create.php exactly:")
print("  - Header: 'New Sale' + Today button")
print("  - Workspace: Customer panel (4/12) + Product panel (8/12)")
print("  - Cart dock: 'Carts' header + tabs + empty-cart hint")
print("    (cart panel only appears once a customer is picked)")
print("  - Sticky bar: 'No active cart' + disabled Finalize button")
print()
print("Ready to commit + push. User should reload /admin/sales/cart to verify.")
