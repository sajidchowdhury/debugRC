@extends('layouts.admin')

@section('content')
@php
    // Pre-render the initial cart payload (if a customer is pre-selected)
    // so the JS can boot from server data without an extra round-trip.
    $initialCart = null;
    if (!empty($cartData)) {
        $initialCart = [
            'cart' => [
                'id'            => $cartData['cart']->id ?? null,
                'is_soft_hold'  => (bool) ($cartData['cart']->is_soft_hold ?? false),
                'customer_id'   => $cartData['cart']->customer_id ?? null,
                'branch_id'     => $cartData['cart']->branch_id ?? null,
                'updated_at'    => optional($cartData['cart']->updated_at ?? null)->toIso8601String(),
            ],
            'items'      => $cartData['items'] ?? [],
            'subtotal'   => (float) ($cartData['subtotal'] ?? 0),
            'validation' => $cartData['validation'] ?? [
                'valid' => true, 'message' => 'Cart is empty',
                'stock_errors' => [], 'rate_errors' => [],
            ],
        ];
    }

    $branchName = session('branch_name', 'No Branch');
@endphp

<div class="container-fluid py-2" id="salesCartApp"
     data-branch-id="{{ (int) $branchId }}"
     data-customer-id="{{ $selectedCustomerId ?? '' }}">

    {{-- ===================== HERO HEADER ===================== --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background:linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-cart-shopping me-2"></i>{{ $title ?? 'Sales Cart' }}
            </h1>
            <p class="mb-0 opacity-75">
                <i class="fas fa-building me-1"></i> Branch: {{ $branchName }}
                <span class="mx-2 opacity-50">•</span>
                <i class="fas fa-receipt me-1"></i> Build a draft invoice before godown dispatch.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <a href="{{ route('admin.customers.index') }}" class="btn btn-outline-light btn-sm" target="_blank">
                <i class="fas fa-users me-1"></i> Customers
            </a>
            <a href="{{ route('admin.products.index') }}" class="btn btn-outline-light btn-sm" target="_blank">
                <i class="fas fa-boxes-stacked me-1"></i> Products
            </a>
        </div>
    </header>

    {{-- ===================== R11: MULTI-CART TABS DOCK ===================== --}}
    {{--
      One pill per open customer-cart. Clicking a pill switches the active
      cart (no page reload). The × button clears that customer's cart and
      removes the tab. Mirrors Legacy `#draft-tabs` in sales/create.php
      (L144–163) + sales-create.js::createOrSwitchTab / closeTab /
      restoreSessionCarts (L657–803).
    --}}
    <div id="draftTabsCard" class="card border-0 shadow-sm mb-3 @if (empty($selectedCustomerId)) d-none @endif">
        <div class="card-body py-2">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="small text-muted">
                    <i class="fas fa-layer-group me-1 text-primary"></i>
                    <strong>Open carts</strong>
                    <span class="text-muted ms-1">— switch customers without losing items</span>
                </div>
                <span id="draftTabsCount" class="badge bg-light text-secondary border">0 carts</span>
            </div>
            <ul class="nav nav-pills flex-nowrap overflow-auto gap-1 py-1" id="draftTabs" role="tablist">
                {{-- pills rendered by JS --}}
            </ul>
            <div id="draftTabsEmpty" class="small text-muted py-1 ps-1">
                <i class="fas fa-info-circle me-1"></i>
                No open carts. Pick a customer below to start a new one.
            </div>
        </div>
    </div>

    {{-- ===================== CUSTOMER SELECTOR ===================== --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-8">
                    <label for="customerSelect" class="form-label small fw-semibold mb-1">
                        <i class="fas fa-user-tag me-1 text-primary"></i> Customer
                    </label>
                    <select id="customerSelect" class="form-select select2" style="width:100%;">
                        <option value="">— Select a customer —</option>
                        @if (!empty($selectedCustomer))
                            <option value="{{ $selectedCustomer->id }}" selected>
                                {{ $selectedCustomer->customer_name }}
                                @if (!empty($selectedCustomer->customer_code)) [{{ $selectedCustomer->customer_code }}] @endif
                            </option>
                        @endif
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <div class="d-flex gap-2">
                        <button type="button" id="btnLoadCart" class="btn btn-primary flex-grow-1">
                            <i class="fas fa-sync me-1"></i> Load Cart
                        </button>
                        <a href="{{ route('admin.sales.cart') }}" class="btn btn-outline-secondary" title="Reset">
                            <i class="fas fa-rotate-left"></i>
                        </a>
                    </div>
                </div>
            </div>

            {{-- ============ R15: CUSTOMER RECENTS CHIPS ============ --}}
            {{--
              Ported from Legacy #customerRecents in
              legacy/app/views/sales/create.php (L47) + sales.js
              `rememberCustomerRecent` / `renderCustomerRecents`
              (L1306–1354). Stores the last 5 picked customers in
              localStorage and renders them as click-to-pick chips
              beneath the customer Select2.

              Clicking a chip re-selects that customer (reusing the
              R11 `switchToCustomer()` flow — Select2 value + tab
              ensure + cart load + credit fetch). This is a
              meaningful UX win for repeat-customer workflows: the
              cashier doesn't have to re-type the name of a customer
              they just served 5 minutes ago.

              Storage shape:
                localStorage['rcerp_sales_customer_recents']
                  = [{id:int, label:string, ts:int(unix_ms)}, ...]
              Capped at 5, deduped by id, most-recent-first.
            --}}
            <div id="customerRecentsRow" class="mt-2 d-none">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="small text-muted text-nowrap">
                        <i class="fas fa-clock-rotate-left me-1 text-primary"></i>Recent:
                    </span>
                    <div id="customerRecents" class="sales-recents d-flex flex-wrap gap-1"></div>
                </div>
            </div>

            {{-- ============ R14: LIVE CREDIT SNAPSHOT PANEL ============ --}}
            {{--
              Ported from Legacy #customerDetailsPanel in
              legacy/app/views/sales/create.php (L72–80). Shows the
              customer's credit_limit, current_due (SUM debit − credit
              from customer_ledger where is_reversed=false), and due_left
              inline so the cashier can see at a glance whether adding
              more items will breach the limit. Mirrors Legacy's
              disp_limit / disp_due / disp_left layout.

              Beyond Legacy parity, we also surface a *projected* new
              balance row that combines current_due + cart subtotal —
              this is the "prevents wasted cart-building" UX win called
              out in audit gap §6.1 #6. The cashier no longer has to
              wait until finalize to discover a credit breach.

              Data is fetched via the R14 endpoint
              GET /admin/sales/cart/customer-details?customer_id=...
              (throttled 60/min). The panel re-renders on every cart
              mutation (add/update/remove/clear) using the cached
              snapshot + the latest cart subtotal — no extra round-trip
              per mutation. A fresh fetch is fired only when the
              customer changes.
            --}}
            <div id="customerDetailsPanel" class="row g-2 mt-2 d-none">
                <div class="col-12">
                    <div class="border rounded-3 bg-light p-2">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                            <span class="small text-muted">
                                <i class="fas fa-wallet me-1 text-primary"></i>
                                <strong>Credit snapshot</strong>
                                <span class="text-muted ms-1">— live from customer ledger</span>
                            </span>
                            <button type="button" id="btnRefreshCredit" class="btn btn-sm btn-link text-decoration-none p-0 m-0"
                                    title="Re-fetch from ledger (in case of recent payments)">
                                <i class="fas fa-rotate me-1"></i><span class="small">Refresh</span>
                            </button>
                        </div>
                        <div class="row g-2 small">
                            <div class="col-6 col-md-3">
                                <div class="text-muted lh-1">Credit limit</div>
                                <div class="fw-bold lh-1" id="cdCreditLimit">—</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="text-muted lh-1">Current due</div>
                                <div class="fw-bold lh-1" id="cdCurrentDue">—</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="text-muted lh-1">Balance left</div>
                                <div class="fw-bold lh-1" id="cdDueLeft">—</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="text-muted lh-1">Cart subtotal</div>
                                <div class="fw-bold lh-1 text-primary" id="cdCartSubtotal">—</div>
                            </div>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <span class="small text-muted">
                                <i class="fas fa-chart-line me-1"></i>
                                Projected new balance <span class="text-muted">(due + cart)</span>
                            </span>
                            <span class="fw-bold" id="cdProjectedBalance">—</span>
                        </div>
                        <div id="cdStatusRow" class="small mt-1">
                            <span id="cdStatus" class="badge bg-secondary">—</span>
                            <span id="cdStatusText" class="text-muted ms-1"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== EMPTY STATE (no customer) ===================== --}}
    <div id="emptyState" class="card border-0 shadow-sm @if (!empty($selectedCustomerId)) d-none @endif">
        <div class="card-body text-center py-5">
            <div class="display-6 text-muted mb-3">
                <i class="fas fa-cart-arrow-down"></i>
            </div>
            <h4 class="text-muted">Select a customer to start building an invoice.</h4>
            <p class="text-muted mb-0">The cart is auto-saved per salesman + customer until you finalize the invoice.</p>
        </div>
    </div>

    {{-- ===================== MAIN TWO-COLUMN WORKSPACE ===================== --}}
    <div id="workspace" class="row g-3 @if (empty($selectedCustomerId)) d-none @endif">

        {{-- ============== LEFT COLUMN (main, col-8) ============== --}}
        <div class="col-12 col-lg-8">

            {{-- ---------- Add Product card ---------- --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom">
                    <span>
                        <i class="fas fa-plus-circle me-2 text-primary"></i>
                        <strong>Add Product</strong>
                    </span>
                </div>
                <div class="card-body">
                    {{-- R10/R15+ simplified: a single Select2 product search box
                         doubles as the barcode scanner entry. Barcode scanners
                         act as HID keyboards — they "type" the code rapidly
                         and end with Enter. Select2's AJAX debounce (250 ms)
                         is long enough to capture the full scan, and
                         `selectOnClose: true` (set in the JS below) makes
                         Enter pick the first highlighted match. If no name
                         match is found, the user's typed term is treated as
                         a product_code and a fallback exact-match lookup is
                         fired against the R1 productByCode endpoint — so
                         scanning a code that isn't a substring of any
                         product_name still resolves correctly. --}}
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-5">
                            <label for="addProduct" class="form-label small fw-semibold mb-1">
                                Product
                                <span class="badge bg-light text-secondary ms-1 fw-normal" title="Type a name or scan a barcode — Enter picks the first match">
                                    <i class="fas fa-barcode me-1"></i>scan ok
                                </span>
                            </label>
                            <select id="addProduct" class="form-select select2" style="width:100%;">
                                <option value="">— Type name / scan code —</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label for="addQty" class="form-label small fw-semibold mb-1">Qty</label>
                            <input type="number" id="addQty" class="form-control" min="0.001" step="0.001" value="1">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="addRate" class="form-label small fw-semibold mb-1">Rate</label>
                            <input type="number" id="addRate" class="form-control" min="0" step="0.01" placeholder="0.00">
                            <div id="rateHint" class="form-text small text-muted mt-1">&nbsp;</div>
                        </div>
                        <div class="col-12 col-md-2">
                            <button type="button" id="btnAddToCart" class="btn btn-success w-100">
                                <i class="fas fa-cart-plus me-1"></i> Add
                            </button>
                        </div>
                    </div>

                    {{-- ============ R13: PRICE-RANGE SLIDER BAND ============ --}}
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

                      Min / max / default come from productCache, which
                      the R1 live-search endpoint populates. If a product
                      has no price range configured (min <= 0 or max <= 0)
                      the panel hides itself — matching Legacy's
                      early-return in updatePriceBandUi.

                      The band is purely informational — actual rate
                      validation still happens server-side in
                      SalesCartService::validateCartItems + the finalize
                      flow. This just gives the cashier a visual cue so
                      they don't have to mentally compare the typed rate
                      against the min/max hint text.
                    --}}
                    <div id="priceRangePanel" class="row g-2 mt-2 d-none">
                        <div class="col-12">
                            <div class="border rounded-3 bg-light p-2">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span class="small fw-semibold text-muted">
                                        <i class="fas fa-tags me-1 text-primary"></i>
                                        Selling range
                                    </span>
                                    <button type="button" id="btnUseDefaultRate" class="btn btn-sm btn-outline-primary py-0 px-2">
                                        <i class="fas fa-arrow-rotate-left me-1"></i>Use default
                                    </button>
                                </div>
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span>Min <b id="priceBandMin" class="text-body">0</b></span>
                                    <span>Default <b id="priceBandDefault" class="text-primary">0</b></span>
                                    <span>Max <b id="priceBandMax" class="text-body">0</b></span>
                                </div>
                                <div class="price-band-track-wrap position-relative" style="height:14px;">
                                    <div class="price-band-track position-absolute top-50 start-0 translate-middle-y w-100 rounded-pill"
                                         style="height:6px;background:#e5e7eb;"></div>
                                    <div id="priceBandFill" class="position-absolute top-50 start-0 translate-middle-y rounded-pill"
                                         style="height:6px;background:linear-gradient(90deg,#22c55e,#4f46e5);width:0%;transition:width .15s ease-out;"></div>
                                    <div id="priceBandDefaultMark" class="position-absolute top-50 translate-middle-y"
                                         style="width:3px;height:14px;background:#4f46e5;opacity:.55;left:50%;transition:left .15s ease-out;border-radius:2px;"
                                         title="Default price"></div>
                                    <div id="priceBandThumb" class="position-absolute top-50"
                                         style="width:14px;height:14px;background:#fff;border:2px solid #4f46e5;border-radius:50%;left:0%;transform:translate(-50%,-50%);transition:left .15s ease-out,border-color .15s;"></div>
                                </div>
                                <div id="priceRangeStatus" class="small mt-2">
                                    <span class="badge bg-success">Rate is within allowed range</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2 mt-2 align-items-center">
                        <div class="col-12 col-md-4">
                            <div class="border rounded-3 bg-light px-3 py-2 d-flex align-items-center gap-2 h-100">
                                <i class="fas fa-warehouse text-secondary"></i>
                                <div>
                                    <div class="small text-muted lh-1">Available (branch)</div>
                                    <div id="addAvailTotal" class="fw-bold lh-1">—</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-8">
                            <div class="small text-muted">Warehouse breakdown appears in the right-hand Availability card.</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ---------- Cart Items table ---------- --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between">
                    <span>
                        <i class="fas fa-list me-2 text-primary"></i>
                        <strong>Cart Items</strong>
                        <span id="itemsCountBadge" class="badge bg-secondary ms-2">0</span>
                    </span>
                    <span class="text-muted small d-none d-md-inline">Inline edits auto-save (300ms debounce)</span>
                </div>
                <div class="card-body p-0">
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
            </div>

            {{-- ---------- Cart actions ---------- --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body d-flex flex-wrap gap-2 align-items-center">
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
                            class="btn btn-primary ms-auto"
                            data-bs-toggle="tooltip" data-bs-placement="top"
                            title="Create a draft sales invoice from this cart (GL posted)">
                        <i class="fas fa-file-invoice-dollar me-1"></i> Finalize Invoice
                    </button>
                </div>
            </div>
        </div>

        {{-- ============== RIGHT COLUMN (aside, col-4) ============== --}}
        <div class="col-12 col-lg-4">

            {{-- ---------- Cart Summary card ---------- --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom">
                    <i class="fas fa-receipt me-2 text-primary"></i>
                    <strong>Summary</strong>
                </div>
                <div class="card-body">
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
            </div>

            {{-- ---------- Validation status card ---------- --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between">
                    <span>
                        <i class="fas fa-clipboard-check me-2 text-primary"></i>
                        <strong>Validation</strong>
                    </span>
                    <span id="validationBadge" class="badge bg-secondary">Not checked</span>
                </div>
                <div class="card-body">
                    <p id="validationMessage" class="small text-muted mb-2">Click “Validate Cart” to run the hard gate.</p>

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
            </div>

            {{-- ---------- Availability card ---------- --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between">
                    <span>
                        <i class="fas fa-warehouse me-2 text-primary"></i>
                        <strong>Availability</strong>
                    </span>
                    <span id="availProductBadge" class="badge bg-secondary">No product</span>
                </div>
                <div class="card-body">
                    <div id="availEmpty" class="text-center text-muted small py-3">
                        <i class="fas fa-magnifying-glass me-1"></i> Select a product in “Add Product” to see per-warehouse availability.
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
@endsection

@push('css')
<style>
    /* ============================================================
       R15: Customer recents chips
       ============================================================ */
    .sales-recents .btn {
        font-size: 0.78rem;
        border-radius: 999px;
        padding: 0.25rem 0.75rem;
        background: #f3f4ff;
        border: 1px solid #c7d2fe;
        color: #4f46e5;
        line-height: 1.4;
        transition: background .15s ease, transform .1s ease;
    }
    .sales-recents .btn:hover {
        background: #e0e7ff;
        transform: translateY(-1px);
    }
    .sales-recents .btn:active {
        transform: translateY(0);
    }

    /* ============================================================
       R16: Sticky bottom bar (always-visible item count + Finalize)
       ============================================================ */
    .sales-pos-sticky-bar {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 1040;
        background: #fff;
        border-top: 1px solid #dee2e6;
        box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.08);
        padding: 0.65rem 1rem calc(0.65rem + env(safe-area-inset-bottom, 0px));
        display: none;
    }
    .sales-pos-sticky-bar.visible {
        display: block;
    }
    .sales-pos-sticky-bar .sticky-summary {
        font-size: 1rem;
        font-weight: 600;
        line-height: 1.2;
    }
    .sales-pos-sticky-bar .sticky-summary .sticky-count {
        font-size: 1.1rem;
        font-weight: 700;
        color: #4f46e5;
    }
    .sales-pos-sticky-bar .sticky-summary .sticky-total {
        font-size: 1.05rem;
        font-weight: 700;
        color: #059669;
    }
    .sales-pos-sticky-bar .btn-finalize {
        min-height: 48px;
        font-size: 1.05rem;
        font-weight: 600;
        padding-left: 1.25rem;
        padding-right: 1.25rem;
    }
    /* Make room for the sticky bar so it never covers the last cart row. */
    body:has(#posStickyBar.visible) .container-fluid#salesCartApp {
        padding-bottom: 5.5rem;
    }
    /* Fallback for browsers without :has() — add the class from JS. */
    body.pos-sticky-visible .container-fluid#salesCartApp {
        padding-bottom: 5.5rem;
    }

    /* ============================================================
       R17: Mobile cart cards with swipe-to-delete
       ============================================================ */
    .sales-cart-desktop { display: block; }
    .sales-cart-mobile { display: none; }

    .sales-cart-line {
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 0.75rem;
        margin: 0.5rem;
        background: #fff;
        position: relative;
        overflow: hidden;
        transition: transform .2s ease, opacity .2s ease;
    }
    .sales-cart-line.swiping {
        transform: translateX(-80px);
        opacity: 0.85;
    }
    .sales-cart-line .line-title {
        font-size: 1rem;
        font-weight: 600;
        line-height: 1.3;
        word-break: break-word;
    }
    .sales-cart-line .line-meta {
        font-size: 0.85rem;
        color: #6b7280;
        margin-top: 0.15rem;
    }
    .sales-cart-line .line-total {
        font-weight: 700;
        color: #059669;
    }
    .sales-cart-line .cart-qty,
    .sales-cart-line .cart-rate {
        min-height: 44px;
        font-size: 16px;
    }
    .sales-cart-line .cart-remove {
        min-height: 40px;
        min-width: 40px;
    }
    /* The swipe reveal background — a red "delete" hint that
       becomes visible as the card slides left. */
    .sales-cart-line::before {
        content: "\f1f8";  /* fa-trash */
        font-family: "Font Awesome 6 Free", "Font Awesome 5 Free", "FontAwesome", sans-serif;
        font-weight: 900;
        position: absolute;
        right: 0;
        top: 0;
        bottom: 0;
        width: 80px;
        background: #dc2626;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        z-index: 0;
    }
    .sales-cart-line > * {
        position: relative;
        z-index: 1;
        background: #fff;
    }
    .sales-cart-line .line-title,
    .sales-cart-line .line-meta {
        background: transparent;
    }

    @media (max-width: 767.98px) {
        .sales-cart-desktop { display: none !important; }
        .sales-cart-mobile { display: block !important; }
    }
</style>
@endpush

@push('scripts')
<script>
(function () {
    'use strict';

    // -------- Bootstrap data from server --------
    var BRANCH_ID   = parseInt(document.getElementById('salesCartApp').dataset.branchId || '0', 10);
    var INITIAL_CID = document.getElementById('salesCartApp').dataset.customerId;
    var INITIAL_CART = @json($initialCart);
    var CSRF_TOKEN  = window.CSRF_TOKEN;

    // -------- AJAX base helpers --------
    function ajaxPost(url, payload) {
        return $.ajax({
            url: url,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload || {}),
            headers: { 'X-CSRF-TOKEN': CSRF_TOKEN, 'X-Requested-With': 'XMLHttpRequest' }
        });
    }
    function ajaxGet(url, params) {
        return $.ajax({
            url: url,
            method: 'GET',
            dataType: 'json',
            data: params || {},
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
    }

    // -------- Endpoint roots (use route() values injected below) --------
    var ENDPOINTS = {
        load:         "{{ route('admin.sales.cart.load') }}",
        add:          "{{ route('admin.sales.cart.add') }}",
        update:       "{{ route('admin.sales.cart.update') }}",
        remove:       "{{ route('admin.sales.cart.remove') }}",
        clear:        "{{ route('admin.sales.cart.clear') }}",
        validate:     "{{ route('admin.sales.cart.validate') }}",
        softHold:     "{{ route('admin.sales.cart.softHold') }}",
        availability: "{{ route('admin.sales.cart.availability') }}",
        finalize:     "{{ route('admin.sales.finalize') }}",
        creditCheck:  "{{ route('admin.sales.credit-check') }}",
        invoiceShow:  "{{ route('admin.sales-invoices.index') }}",
        branchDispatchers: "{{ route('admin.sales-invoices.branch-dispatchers') }}",
        // R1: live search endpoints (ported from Legacy sales/search_customer
        // & sales/search_product).
        searchCustomer: "{{ route('admin.sales.cart.search-customer') }}",
        searchProduct:  "{{ route('admin.sales.cart.search-product') }}",
        productByCode:  "{{ route('admin.sales.cart.product-by-code') }}",
        // R11: list all open draft carts for the #draft-tabs dock.
        listDrafts:     "{{ route('admin.sales.cart.list-drafts') }}",
        // R14: live customer credit snapshot (credit_limit, current_due,
        // due_left) for the inline credit panel.
        customerDetails: "{{ route('admin.sales.cart.customer-details') }}",
    };

    // -------- State --------
    var state = {
        customerId: INITIAL_CID ? parseInt(INITIAL_CID, 10) : null,
        cart: INITIAL_CART,         // {cart, items, subtotal, validation} or null
        softHold: !!(INITIAL_CART && INITIAL_CART.cart && INITIAL_CART.cart.is_soft_hold),
        validation: INITIAL_CART ? INITIAL_CART.validation : null,
        availBreakdown: [],         // last availability response
        availProductId: null,
        debounceTimers: {},         // productId -> setTimeout handle
        // R13: currently-active product price range (min/max/default) for
        // the #priceRangePanel slider band. Null when no product is
        // selected or the product has no price range configured.
        activePriceRange: null,
        // R14: cached customer credit snapshot from the customer-details
        // endpoint. Re-fetched only when the customer changes; the
        // projected-balance row recomputes locally on every cart
        // mutation using this snapshot + the latest cart subtotal.
        customerCredit: null,
    };

    // R1: in-memory product cache (id -> {id, product_code, product_name,
    // default_rate, min_rate, max_rate, available_qty}). Populated by the
    // live search endpoint as the user types, and consulted when rendering
    // the cart table / availability card so we no longer depend on a
    // pre-rendered <option> list.
    var productCache = {};

    // -------- Money / qty formatting --------
    function fmtMoney(v) {
        var n = parseFloat(v || 0);
        return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fmtQty(v) {
        var n = parseFloat(v || 0);
        return n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 4 });
    }
    function escHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    // -------- Toast / alert helper --------
    function toast(message, type) {
        type = type || 'info';
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: type === 'error' ? 'error' : (type === 'success' ? 'success' : 'info'),
            title: message,
            showConfirmButton: false,
            timer: 2500,
            timerProgressBar: true
        });
    }

    // ============================================================
    // ============== R11: MULTI-CART TABS DOCK ===================
    // ============================================================
    //
    // Mirrors Legacy `#draft-tabs` in sales/create.php (L144–163)
    // + sales-create.js::createOrSwitchTab / switchToTab / closeTab
    // / refreshTabBadge / restoreSessionCarts (L643–803).
    //
    // Each pill = one open customer-cart. Clicking the pill body
    // switches the active cart (no page reload); clicking the ×
    // closes that cart (after a confirm dialog) by calling the
    // existing /cart/clear endpoint, then removes the pill.
    //
    // The pill's badge shows the live item count for that cart.
    // Badges update on every successful cart mutation (add /
    // update / remove / clear) by reading the response payload —
    // no extra round-trip needed.

    // In-memory cache of customer metadata for tabs that were
    // opened by selecting a customer (vs restored from list-drafts).
    // Keyed by customer_id. Used to format tab labels without an
    // extra fetch when the user picks a customer from the Select2.
    var customerCache = {};

    function tabLabelFor(customerId) {
        var c = customerCache[customerId];
        if (!c) return 'Customer #' + customerId;
        var label = (c.shop_name || c.customer_name || '').trim();
        if (c.mobile) label = label ? label + ' · ' + c.mobile : c.mobile;
        return label || ('Customer #' + customerId);
    }

    function tabTitleFor(customerId) {
        // Long-form tooltip text (truncated in the pill body).
        var c = customerCache[customerId];
        if (!c) return 'Customer #' + customerId;
        var parts = [];
        if (c.shop_name) parts.push(c.shop_name);
        if (c.customer_name) parts.push(c.customer_name);
        if (c.mobile) parts.push(c.mobile);
        return parts.join(' · ') || ('Customer #' + customerId);
    }

    function ensureTab(customerId, opts) {
        // opts: { label?:string, itemCount?:int, active?:bool, softHold?:bool }
        // Returns the tab <li> element (jQuery-wrapped).
        if (!customerId) return $();
        customerId = parseInt(customerId, 10);
        opts = opts || {};

        var $li = $('#draftTabLi-' + customerId);
        if ($li.length === 0) {
            // Create the pill
            $li = $(
                '<li class="nav-item draft-tab-item" id="draftTabLi-' + customerId + '" role="presentation">' +
                    '<div class="d-flex align-items-stretch border rounded-2 overflow-hidden" ' +
                         'style="background:#f8f9fc;">' +
                        '<button type="button" class="btn btn-sm draft-tab-link text-start px-2 py-1 border-0 bg-transparent" ' +
                                'id="draftTab-' + customerId + '" ' +
                                'data-customer-id="' + customerId + '" role="tab">' +
                            '<span class="d-block fw-semibold small text-truncate draft-tab-name" style="max-width:180px;">' +
                                escHtml(opts.label || tabLabelFor(customerId)) +
                            '</span>' +
                            '<span class="d-block text-muted" style="font-size:11px;line-height:1.1;">' +
                                '<span class="badge bg-secondary rounded-pill draft-tab-badge">0</span>' +
                                (opts.softHold ? ' <i class="fas fa-pause-circle text-warning ms-1" title="Soft-hold"></i>' : '') +
                            '</span>' +
                        '</button>' +
                        '<button type="button" class="btn btn-sm draft-tab-close border-0 bg-transparent text-danger px-1" ' +
                                'data-customer-id="' + customerId + '" title="Close this cart" ' +
                                'aria-label="Close cart for ' + escHtml(opts.label || tabLabelFor(customerId)) + '">' +
                            '<i class="fas fa-times"></i>' +
                        '</button>' +
                    '</div>' +
                '</li>'
            );
            $('#draftTabs').append($li);
        }

        // Update label / badge if provided
        if (opts.label !== undefined) {
            $li.find('.draft-tab-name').text(opts.label);
        }
        if (opts.itemCount !== undefined) {
            var $badge = $li.find('.draft-tab-badge');
            $badge.text(opts.itemCount);
            $badge.toggleClass('bg-secondary', opts.itemCount === 0)
                  .toggleClass('bg-primary', opts.itemCount > 0);
        }
        if (opts.softHold !== undefined) {
            // Show / hide the soft-hold icon next to the badge.
            var $iconBox = $li.find('.draft-tab-badge').parent();
            $iconBox.find('.fa-pause-circle').remove();
            if (opts.softHold) {
                $iconBox.append(' <i class="fas fa-pause-circle text-warning ms-1" title="Soft-hold"></i>');
            }
        }

        if (opts.active) {
            activateTab(customerId);
        }
        refreshTabDockVisibility();
        return $li;
    }

    function activateTab(customerId) {
        if (!customerId) return;
        customerId = parseInt(customerId, 10);

        // Highlight only the active pill
        $('#draftTabs .draft-tab-link').removeClass('active bg-white shadow-sm');
        $('#draftTabs .draft-tab-item > div').css('background', '#f8f9fc');
        var $active = $('#draftTab-' + customerId);
        if ($active.length) {
            $active.addClass('active bg-white shadow-sm');
            $active.closest('div').css('background', '#fff');
            // Scroll the active tab into view (horizontal scroll)
            var tabEl = $active[0];
            if (tabEl && tabEl.scrollIntoView) {
                tabEl.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
            }
        }
    }

    function removeTab(customerId) {
        $('#draftTabLi-' + parseInt(customerId, 10)).remove();
        refreshTabDockVisibility();
    }

    function refreshTabDockVisibility() {
        var count = $('#draftTabs > li').length;
        $('#draftTabsCount').text(count + (count === 1 ? ' cart' : ' carts'));
        $('#draftTabsEmpty').toggle(count === 0);
        // Keep the dock visible whenever the workspace is visible
        // (so the cashier can open more carts); only hide it in the
        // initial "no customer selected" empty-state view.
        if (count > 0) {
            $('#draftTabsCard').removeClass('d-none');
        }
    }

    function updateActiveTabBadge(itemCount, opts) {
        // itemCount: int (current cart item count)
        // opts: { softHold?:bool, label?:string }
        if (!state.customerId) return;
        var $li = $('#draftTabLi-' + state.customerId);
        if ($li.length === 0) {
            // Tab not yet rendered (e.g. user added an item to a fresh
            // customer's cart before list-drafts finished). Create it.
            ensureTab(state.customerId, {
                itemCount: itemCount,
                active: true,
                softHold: opts && opts.softHold,
                label: opts && opts.label,
            });
            return;
        }
        ensureTab(state.customerId, Object.assign({
            itemCount: itemCount,
            active: true,
        }, opts || {}));
    }

    /**
     * Fetch the list of open carts and render one pill per cart.
     * Called on page load. Mirrors Legacy `restoreSessionCarts`
     * (sales-create.js L733–760).
     */
    function restoreSessionCarts() {
        return ajaxGet(ENDPOINTS.listDrafts, {})
            .done(function (carts) {
                if (!carts || !carts.length) return;
                carts.forEach(function (c) {
                    // Cache customer metadata so tabLabelFor can render
                    // pills even before /load resolves.
                    customerCache[c.customer_id] = {
                        id: c.customer_id,
                        shop_name: c.shop_name,
                        customer_name: c.customer_name,
                        mobile: c.mobile,
                    };
                    ensureTab(c.customer_id, {
                        label: c.label,
                        itemCount: c.item_count,
                        softHold: c.is_soft_hold,
                        active: false,
                    });
                });

                // If we already have an INITIAL_CID (from ?customer_id=)
                // activate that tab; otherwise activate the first one
                // (busiest cart, since list-drafts sorts by item_count
                // desc then updated_at desc — matches Legacy).
                var firstCid = parseInt(carts[0].customer_id, 10);
                var activateCid = state.customerId || firstCid;
                if (activateCid) {
                    activateTab(activateCid);
                    // If state.customerId wasn't set yet (no ?customer_id=)
                    // we need to also load the cart + sync the Select2.
                    if (!state.customerId) {
                        switchToCustomer(activateCid, { skipTabEnsure: true });
                    }
                }
            })
            .fail(function (xhr) {
                // Non-fatal — the dock just stays empty.
                console.warn('list-drafts failed', xhr?.responseJSON || xhr?.statusText);
            });
    }

    /**
     * Switch the active cart to a different customer.
     * - Updates the Select2 (#customerSelect) value
     * - Triggers `change` which calls loadCart(cid)
     * - Ensures a tab exists + activates it
     *
     * opts.skipTabEnsure (bool) — when called from inside
     * restoreSessionCarts, the tab has already been ensured; skip
     * the duplicate call.
     */
    function switchToCustomer(customerId, opts) {
        opts = opts || {};
        customerId = parseInt(customerId, 10);
        if (!customerId) return;

        // Ensure the Select2 has an <option> for this customer so
        // .val() actually selects it. (Select2 AJAX only has options
        // the user typed for; we synthesize one from the cache.)
        var c = customerCache[customerId];
        if (c) {
            var label = (c.shop_name || c.customer_name || '#' + customerId);
            if (c.customer_code) label += ' [' + c.customer_code + ']';
            if (c.mobile) label += ' · ' + c.mobile;
            if ($('#customerSelect option[value="' + customerId + '"]').length === 0) {
                var $opt = $('<option></option>').val(customerId).text(label);
                $('#customerSelect').append($opt);
            }
        }
        $('#customerSelect').val(customerId).trigger('change');

        if (!opts.skipTabEnsure) {
            ensureTab(customerId, { active: true });
        } else {
            activateTab(customerId);
        }

        // Update the URL so a refresh preserves selection
        if (window.history && history.replaceState) {
            history.replaceState(null, '', window.location.pathname + '?customer_id=' + customerId);
        }
    }

    /**
     * Close (clear) the cart for a customer and remove its tab.
     * Mirrors Legacy `closeTab` (sales-create.js L762–792).
     *
     * If the closed tab was the active one, switch to the next
     * remaining tab; if no tabs remain, show the empty state.
     */
    function closeTabCart(customerId) {
        customerId = parseInt(customerId, 10);
        if (!customerId) return;

        Swal.fire({
            title: 'Close this cart?',
            text: 'All items for this customer will be removed.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: '<i class="fas fa-check me-1"></i> Yes, close',
            cancelButtonText: 'Cancel',
        }).then(function (result) {
            if (!result.isConfirmed) return;

            // Clear the cart via the existing endpoint (already does
            // SalesCartService::clearCart under the hood, which writes
            // the R4 audit-log entry too).
            ajaxPost(ENDPOINTS.clear, { customer_id: customerId })
                .done(function (resp) {
                    if (resp && resp.status === 'success') {
                        removeTab(customerId);
                        if (parseInt(state.customerId, 10) === customerId) {
                            // Active tab was closed — switch to next remaining
                            var $next = $('#draftTabs .draft-tab-link').first();
                            if ($next.length) {
                                var nextCid = parseInt($next.data('customer-id'), 10);
                                switchToCustomer(nextCid);
                            } else {
                                // No carts left → reset to empty state
                                state.customerId = null;
                                state.cart = null;
                                state.validation = null;
                                $('#customerSelect').val('').trigger('change');
                                setWorkspaceVisible(false);
                                renderAll();
                                if (window.history && history.replaceState) {
                                    history.replaceState(null, '', window.location.pathname);
                                }
                                $('#draftTabsCard').addClass('d-none');
                            }
                        }
                        toast('Cart closed', 'success');
                    } else {
                        toast((resp && resp.message) || 'Could not close cart.', 'error');
                    }
                })
                .fail(function (xhr) {
                    toast('Close failed: ' + (xhr.responseJSON?.message || xhr.statusText), 'error');
                });
        });
    }

    // ---- Tab dock event wiring (delegated, runs once) ----
    function initDraftTabsDock() {
        // Pill body click → switch cart
        $(document).on('click', '.draft-tab-link', function (e) {
            e.preventDefault();
            var cid = parseInt($(this).data('customer-id'), 10);
            if (!cid) return;
            if (parseInt(state.customerId, 10) === cid) return; // already active
            switchToCustomer(cid);
        });

        // × close button
        $(document).on('click', '.draft-tab-close', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var cid = parseInt($(this).data('customer-id'), 10);
            closeTabCart(cid);
        });
    }

    // ============================================================
    // ============== RENDERING ===================================
    // ============================================================

    function renderAll() {
        renderCartTable();
        renderSummary();
        renderValidation(state.validation);
        // R14: re-render the credit snapshot's projected-balance row
        // using the latest cart subtotal. The cached snapshot
        // (state.customerCredit) is reused — no extra round-trip.
        renderCustomerDetails();
        // Finalize button enabled state depends on cart + validation
        var valid   = state.validation ? !!state.validation.valid : false;
        var hasItems = state.cart && state.cart.items && state.cart.items.length > 0;
        var btnFinalize = document.getElementById('btnFinalize');
        btnFinalize.disabled = !(hasItems && valid);
        // R16: refresh the sticky bottom bar so item count + grand
        // total stay in sync with every cart mutation.
        updatePosStickyBar();
    }

    function renderCartTable() {
        var $body = $('#cartItemsBody');
        var $mobile = $('#cartItemsMobile');
        $body.empty();
        $mobile.empty();

        var items = (state.cart && state.cart.items) ? state.cart.items : [];
        $('#itemsCountBadge').text(items.length);
        $('#cartEmptyRow').toggle(items.length === 0);

        items.forEach(function (item, idx) {
            var productId = parseInt(item.product_id, 10);
            var qty   = parseFloat(item.qty || 0);
            var rate  = parseFloat(item.rate || 0);
            var total = parseFloat(item.total || (qty * rate));
            var avail = item.available_qty !== undefined ? parseFloat(item.available_qty) : null;
            var minR  = item.min_rate !== null && item.min_rate !== undefined ? parseFloat(item.min_rate) : null;
            var maxR  = item.max_rate !== null && item.max_rate !== undefined ? parseFloat(item.max_rate) : null;

            var availClass = '';
            if (avail !== null) {
                if (qty > avail + 0.0001) availClass = 'text-danger fw-bold';
                else if (qty > avail * 0.9) availClass = 'text-warning';
                else availClass = 'text-success';
            }

            var rateMinAttr = (minR !== null && minR > 0) ? ' min="' + minR.toFixed(2) + '"' : '';
            var rateMaxAttr = (maxR !== null && maxR > 0) ? ' max="' + maxR.toFixed(2) + '"' : '';

            // ---- Desktop <tr> row ----
            var row =
                '<tr data-product-id="' + productId + '">' +
                    '<td>' +
                        '<div class="fw-semibold">' + escHtml(item.product_name) + '</div>' +
                        '<div class="small text-muted">#' + productId + '</div>' +
                    '</td>' +
                    '<td>' +
                        '<input type="number" class="form-control form-control-sm cart-qty" min="0.001" step="0.001" value="' + qty + '">' +
                    '</td>' +
                    '<td>' +
                        '<input type="number" class="form-control form-control-sm cart-rate" min="0" step="0.01" value="' + rate.toFixed(2) + '"' + rateMinAttr + rateMaxAttr + '>' +
                        (minR !== null
                            ? '<div class="form-text small text-muted">Min ' + fmtMoney(minR) + ' / Max ' + fmtMoney(maxR) + '</div>'
                            : '') +
                    '</td>' +
                    '<td class="text-end fw-semibold cart-total">' + fmtMoney(total) + '</td>' +
                    '<td class="text-end ' + availClass + ' cart-avail">' +
                        (avail !== null ? fmtQty(avail) : '—') +
                    '</td>' +
                    '<td class="text-center">' +
                        '<button type="button" class="btn btn-sm btn-outline-danger cart-remove" title="Remove">' +
                            '<i class="fas fa-trash"></i>' +
                        '</button>' +
                    '</td>' +
                '</tr>';
            $body.append(row);

            // ---- Mobile .sales-cart-line card ----
            // Mirrors Legacy sales-cart-line markup (sales.js L831-846):
            //   - Title + delete button on top row
            //   - Line meta (avail + total)
            //   - Rate input + qty input side-by-side (large tap targets)
            // Shares the SAME .cart-qty / .cart-rate / .cart-remove /
            // .cart-total classes as the desktop row so the existing
            // delegated handlers + debouncedUpdate work for both.
            var availText = avail !== null ? fmtQty(avail) + ' avail' : '—';
            var card =
                '<div class="sales-cart-line" data-product-id="' + productId + '">' +
                    '<div class="d-flex justify-content-between align-items-start gap-2">' +
                        '<div class="flex-grow-1">' +
                            '<div class="line-title">' + escHtml(item.product_name) + '</div>' +
                            '<div class="line-meta">#' + productId +
                                (minR !== null
                                    ? ' · Range ' + fmtMoney(minR) + '–' + fmtMoney(maxR)
                                    : '') +
                            '</div>' +
                        '</div>' +
                        '<button type="button" class="btn btn-sm btn-outline-danger cart-remove flex-shrink-0" title="Remove">' +
                            '<i class="fas fa-trash"></i>' +
                        '</button>' +
                    '</div>' +
                    '<div class="d-flex justify-content-between align-items-center mt-2">' +
                        '<span class="line-meta">' + availText + '</span>' +
                        '<span class="line-total">৳' + fmtMoney(total) + '</span>' +
                    '</div>' +
                    '<div class="d-flex gap-2 mt-2">' +
                        '<div class="flex-grow-1">' +
                            '<label class="small text-muted mb-0">Rate</label>' +
                            '<input type="number" class="form-control form-control-sm cart-rate" min="0" step="0.01" value="' + rate.toFixed(2) + '"' + rateMinAttr + rateMaxAttr + '>' +
                        '</div>' +
                        '<div class="flex-grow-1">' +
                            '<label class="small text-muted mb-0">Qty</label>' +
                            '<input type="number" class="form-control form-control-sm cart-qty" min="0.001" step="0.001" value="' + qty + '">' +
                        '</div>' +
                    '</div>' +
                    '<div class="cart-total d-none">' + fmtMoney(total) + '</div>' +
                '</div>';
            $mobile.append(card);
        });

        // Subtotal cell (desktop tfoot)
        $('#cartSubtotalCell').text(fmtMoney(state.cart ? state.cart.subtotal : 0));

        // R17: (re)bind swipe-to-delete on the freshly-rendered mobile cards.
        initCartSwipeRemove();
    }

    function renderSummary() {
        var items = (state.cart && state.cart.items) ? state.cart.items : [];
        var customerName = '—';
        if (state.customerId) {
            var $opt = $('#customerSelect option[value="' + state.customerId + '"]');
            if ($opt.length) customerName = $opt.text().trim();
        }
        $('#sumCustomer').text(customerName);
        $('#sumItems').text(items.length);
        $('#sumSubtotal').text(fmtMoney(state.cart ? state.cart.subtotal : 0));

        var $badge = $('#sumSoftHold');
        if (state.softHold) {
            $badge.removeClass('bg-secondary').addClass('bg-warning text-dark').text('Yes');
            $('#softHoldBtnLabel').text('Release Hold');
            $('#btnSoftHold').removeClass('btn-outline-warning').addClass('btn-warning');
        } else {
            $badge.removeClass('bg-warning text-dark').addClass('bg-secondary').text('No');
            $('#softHoldBtnLabel').text('Soft Hold');
            $('#btnSoftHold').removeClass('btn-warning').addClass('btn-outline-warning');
        }
    }

    function renderValidation(validation) {
        var $badge = $('#validationBadge');
        var $msg   = $('#validationMessage');
        var $stockBox = $('#stockErrorsBox');
        var $rateBox  = $('#rateErrorsBox');

        $stockBox.addClass('d-none').find('ul').empty();
        $rateBox.addClass('d-none').find('ul').empty();

        if (!validation) {
            $badge.removeClass('bg-success bg-danger').addClass('bg-secondary').text('Not checked');
            $msg.html('Click <strong>“Validate Cart”</strong> to run the hard gate.');
            return;
        }

        if (validation.valid) {
            $badge.removeClass('bg-secondary bg-danger').addClass('bg-success').text('Valid');
            $msg.html('<i class="fas fa-circle-check text-success me-1"></i>' + escHtml(validation.message || 'Cart is valid'));
        } else {
            $badge.removeClass('bg-secondary bg-success').addClass('bg-danger').text('Invalid');
            $msg.html('<i class="fas fa-circle-xmark text-danger me-1"></i>' + escHtml(validation.message || 'Cart has issues'));
        }

        (validation.stock_errors || []).forEach(function (e) {
            $('#stockErrorsList').append(
                '<li class="text-danger mb-1">' +
                    '<strong>' + escHtml(e.product_name) + '</strong> — ' +
                    'requested <strong>' + fmtQty(e.requested) + '</strong>, ' +
                    'available ' + fmtQty(e.available) + ' ' +
                    '(short ' + fmtQty(e.shortfall) + ')' +
                '</li>'
            );
        });
        if ((validation.stock_errors || []).length) $stockBox.removeClass('d-none');

        (validation.rate_errors || []).forEach(function (e) {
            $('#rateErrorsList').append(
                '<li class="text-warning mb-1">' +
                    '<strong>' + escHtml(e.product_name) + '</strong> — ' +
                    'rate ' + fmtMoney(e.rate) + ' (allowed ' + fmtMoney(e.min_rate) + '–' + fmtMoney(e.max_rate) + ')' +
                '</li>'
            );
        });
        if ((validation.rate_errors || []).length) $rateBox.removeClass('d-none');
    }

    function renderAvailability(payload) {
        var $empty = $('#availEmpty');
        var $wrap  = $('#availTableWrap');
        var $body  = $('#availTableBody');
        var $badge = $('#availProductBadge');

        if (!payload || !payload.product_id) {
            $empty.removeClass('d-none');
            $wrap.addClass('d-none');
            $badge.removeClass('bg-info text-dark bg-secondary').addClass('bg-secondary').text('No product');
            $('#addAvailTotal').text('—');
            return;
        }

        var breakdown = payload.warehouse_breakdown || [];
        // R1: prefer productCache (AJAX-populated) for the label; fall back
        // to a transient <option> rendered by Select2, and finally to a
        // bare "#id" placeholder.
        var cached = productCache[payload.product_id];
        var $opt = $('#addProduct option[value="' + payload.product_id + '"]');
        var label;
        if (cached && cached.product_name) {
            label = cached.product_name + (cached.product_code ? ' [' + cached.product_code + ']' : '');
        } else if ($opt.length) {
            label = $opt.text().trim();
        } else {
            label = '#' + payload.product_id;
        }

        $badge.removeClass('bg-secondary').addClass('bg-info text-dark').text(label.substring(0, 28));
        $empty.addClass('d-none');
        $wrap.removeClass('d-none');

        $body.empty();
        var totalPhysical = 0, totalPipeline = 0, totalAvailable = 0;
        breakdown.forEach(function (w) {
            var phys = parseFloat(w.physical_qty || 0);
            var pipe = parseFloat(w.pipeline_qty || 0);
            var avl  = parseFloat(w.available_qty || 0);
            totalPhysical += phys;
            totalPipeline += pipe;
            totalAvailable += avl;
            $body.append(
                '<tr>' +
                    '<td>' + escHtml(w.warehouse_name) + '</td>' +
                    '<td class="text-end">' + fmtQty(phys) + '</td>' +
                    '<td class="text-end text-warning">' + fmtQty(pipe) + '</td>' +
                    '<td class="text-end fw-semibold text-success">' + fmtQty(avl) + '</td>' +
                '</tr>'
            );
        });
        $('#availTotalPhysical').text(fmtQty(totalPhysical));
        $('#availTotalPipeline').text(fmtQty(totalPipeline));
        $('#availTotalAvailable').text(fmtQty(totalAvailable));
        $('#addAvailTotal').text(fmtQty(payload.available_qty) + ' ' + (breakdown.length === 1 ? 'warehouse' : 'warehouses'));
    }

    // ============================================================
    // ============== R13: PRICE-RANGE SLIDER BAND ================
    // ============================================================
    //
    // Mirrors Legacy `updatePriceBandUi` (sales-create.js L129–187).
    // Renders a visual band: track + green→purple fill (current rate
    // position) + default-rate mark + thumb that follows #addRate.
    // Status text below turns green / amber / red depending on where
    // the typed rate sits within the [min, max] window.
    //
    // The band reads from `state.activePriceRange`, which is set by
    // `setActivePriceRange(product)` whenever the user picks a
    // product (from Select2 or via the R10 barcode scan). When the
    // rate input changes, `updatePriceBandUi()` is called again to
    // reposition the thumb and refresh the status.

    function setActivePriceRange(product) {
        if (!product) {
            state.activePriceRange = null;
            updatePriceBandUi();
            return;
        }
        var min = parseFloat(product.min_rate) || 0;
        var max = parseFloat(product.max_rate) || 0;
        var def = parseFloat(product.default_rate ?? product.price) || 0;
        if (min <= 0 || max <= 0) {
            // Legacy early-returns when there's no usable range.
            state.activePriceRange = null;
            updatePriceBandUi();
            return;
        }
        state.activePriceRange = { min_rate: min, max_rate: max, default_rate: def };
        updatePriceBandUi();
    }

    function rateRangeStatus(rate, min, max) {
        // Returns 'ok' | 'warn' | 'bad' mirroring Legacy
        // salesRateRangeStatus (in sales.js). 'warn' fires when the
        // rate is within range but within 10% of the minimum — gives
        // the cashier a margin heads-up without blocking the add.
        if (rate < min || rate > max) return 'bad';
        var span = max - min;
        if (span > 0 && (rate - min) / span < 0.10) return 'warn';
        return 'ok';
    }

    function updatePriceBandUi() {
        var $panel = $('#priceRangePanel');
        var $rate  = $('#addRate');
        if (!$panel.length || !$rate.length) return;

        if (!state.activePriceRange) {
            $panel.addClass('d-none');
            $rate.removeAttr('min').removeAttr('max');
            return;
        }

        var r = state.activePriceRange;
        var min = r.min_rate, max = r.max_rate, def = r.default_rate;
        var rate = parseFloat($rate.val()) || 0;
        var span = max - min;

        $panel.removeClass('d-none');
        $('#priceBandMin').text('৳' + fmtMoney(min));
        $('#priceBandMax').text('৳' + fmtMoney(max));
        $('#priceBandDefault').text('৳' + fmtMoney(def));

        // Clamp rate input attributes so the browser's own stepper
        // also respects the range (HTML5 min/max on <input type=number>).
        $rate.attr('min', min.toFixed(2)).attr('max', max.toFixed(2));

        var pct    = span > 0 ? Math.min(100, Math.max(0, ((rate - min) / span) * 100)) : 0;
        var defPct = span > 0 ? Math.min(100, Math.max(0, ((def - min) / span) * 100)) : 50;

        $('#priceBandFill').css('width', pct + '%');
        $('#priceBandThumb').css('left', pct + '%');
        $('#priceBandDefaultMark').css('left', defPct + '%');

        var st = rateRangeStatus(rate, min, max);
        var $thumb = $('#priceBandThumb');
        $thumb.css('border-color', st === 'bad' ? '#dc2626' : (st === 'warn' ? '#b45309' : '#4f46e5'));

        var $status = $('#priceRangeStatus');
        $status.empty();
        if (st === 'bad') {
            $status.append('<span class="badge bg-danger">' +
                'Out of range — must be ৳' + fmtMoney(min) + ' – ৳' + fmtMoney(max) +
                '</span>');
        } else if (st === 'warn') {
            $status.append('<span class="badge bg-warning text-dark">' +
                'Near minimum — check margin' +
                '</span>');
        } else {
            $status.append('<span class="badge bg-success">' +
                'Rate is within allowed range' +
                '</span>');
        }
    }

    // ============================================================
    // ============== R14: LIVE CREDIT SNAPSHOT ===================
    // ============================================================
    //
    // Mirrors Legacy `customerDetailsPanel` (sales/create.php L72–80)
    // + the JS that fetches customer_details and populates disp_limit /
    // disp_due / disp_left.
    //
    // Beyond Legacy parity, we also surface a *projected* new balance
    // row that combines current_due + cart subtotal — this is the
    // "prevents wasted cart-building" UX win called out in audit
    // gap §6.1 #6. The cashier sees the projected balance update in
    // real time as they add/remove/edit cart items, with a colored
    // status badge:
    //   - bg-success: projected ≤ credit_limit (room to spare)
    //   - bg-warning: projected within 10% of credit_limit (tight)
    //   - bg-danger:  projected > credit_limit (will breach)
    //
    // The snapshot is fetched once per customer change (and on
    // explicit "Refresh" click). The projected row recomputes locally
    // on every cart mutation using the cached snapshot — no extra
    // round-trip per add/remove.

    function fetchCustomerDetails(customerId) {
        if (!customerId) {
            state.customerCredit = null;
            renderCustomerDetails();
            return;
        }
        ajaxGet(ENDPOINTS.customerDetails, { customer_id: customerId })
            .done(function (data) {
                state.customerCredit = data || null;
                renderCustomerDetails();
            })
            .fail(function (xhr) {
                // Non-fatal — the panel just hides. The finalize-time
                // credit-check endpoint is still authoritative.
                console.warn('customer-details failed', xhr?.responseJSON || xhr?.statusText);
                state.customerCredit = null;
                renderCustomerDetails();
            });
    }

    function renderCustomerDetails() {
        var $panel = $('#customerDetailsPanel');
        if (!$panel.length) return;

        if (!state.customerCredit || !state.customerId) {
            $panel.addClass('d-none');
            return;
        }
        $panel.removeClass('d-none');

        var c = state.customerCredit;
        var cartSub = state.cart ? (parseFloat(state.cart.subtotal) || 0) : 0;
        var limit    = parseFloat(c.credit_limit) || 0;
        var due      = parseFloat(c.current_due) || 0;
        var left     = parseFloat(c.due_left);
        if (isNaN(left)) left = limit - due;
        var projNew  = due + cartSub;
        var projLeft = limit - projNew;

        $('#cdCreditLimit').text('৳' + fmtMoney(limit));
        $('#cdCurrentDue').text('৳' + fmtMoney(due));

        // Balance left (existing ledger position, no cart yet) —
        // red if already over, amber if within 10% of limit, green otherwise.
        var $leftEl = $('#cdDueLeft');
        $leftEl.text('৳' + fmtMoney(left))
               .removeClass('text-success text-warning text-danger');
        if (limit > 0 && left < 0) $leftEl.addClass('text-danger');
        else if (limit > 0 && left < limit * 0.10) $leftEl.addClass('text-warning');
        else $leftEl.addClass('text-success');

        $('#cdCartSubtotal').text('৳' + fmtMoney(cartSub));

        // Projected new balance (due + cart subtotal)
        var $projEl = $('#cdProjectedBalance');
        $projEl.text('৳' + fmtMoney(projNew))
               .removeClass('text-success text-warning text-danger');

        var $status = $('#cdStatus');
        var $statusText = $('#cdStatusText');
        $status.removeClass('bg-success bg-warning bg-danger bg-secondary').addClass('bg-secondary');
        $statusText.text('');

        if (limit <= 0) {
            // No credit limit configured — show a neutral "no limit" hint
            // so the cashier knows the panel is live but the customer has
            // no enforceable ceiling.
            $status.text('No limit set');
            $statusText.text('credit_limit = 0 — no ceiling enforced');
        } else if (projLeft < 0) {
            $projEl.addClass('text-danger');
            $status.removeClass('bg-secondary').addClass('bg-danger').text('Will breach');
            $statusText.text('Cart pushes balance ৳' + fmtMoney(Math.abs(projLeft)) + ' over the limit — finalize will require override.');
        } else if (projLeft < limit * 0.10) {
            $projEl.addClass('text-warning');
            $status.removeClass('bg-secondary').addClass('bg-warning text-dark').text('Tight');
            $statusText.text('Less than 10% of credit limit would remain after finalize.');
        } else {
            $projEl.addClass('text-success');
            $status.removeClass('bg-secondary').addClass('bg-success').text('OK');
            $statusText.text('Plenty of headroom — finalize will pass credit check.');
        }
    }

    // ============================================================
    // ============== ACTIONS =====================================
    // ============================================================

    function setWorkspaceVisible(customerSelected) {
        if (customerSelected) {
            $('#workspace').removeClass('d-none');
            $('#emptyState').addClass('d-none');
        } else {
            $('#workspace').addClass('d-none');
            $('#emptyState').removeClass('d-none');
        }
    }

    function loadCart(customerId) {
        if (!customerId) {
            state.customerId = null;
            state.cart = null;
            state.validation = null;
            setWorkspaceVisible(false);
            renderAll();
            return;
        }
        state.customerId = parseInt(customerId, 10);
        ajaxPost(ENDPOINTS.load, { customer_id: state.customerId })
            .done(function (data) {
                state.cart = data;
                state.softHold = !!(data.cart && data.cart.is_soft_hold);
                state.validation = data.validation || null;
                setWorkspaceVisible(true);
                renderAll();

                // R11: ensure a tab exists for this customer + update its
                // badge from the freshly-loaded cart. The label comes from
                // customerCache (populated by Select2's processResults) or
                // falls back to "Customer #ID".
                updateActiveTabBadge(
                    (data.items || []).length,
                    { softHold: state.softHold, label: tabLabelFor(state.customerId) }
                );
                activateTab(state.customerId);
            })
            .fail(function (xhr) {
                toast('Failed to load cart: ' + (xhr.responseJSON?.message || xhr.statusText), 'error');
            });
    }

    function addToCart() {
        if (!state.customerId) { toast('Select a customer first.', 'error'); return; }
        var productId = parseInt($('#addProduct').val(), 10);
        var qty  = parseFloat($('#addQty').val());
        var rate = parseFloat($('#addRate').val());
        if (!productId || !(qty > 0) || !(rate >= 0)) {
            toast('Pick a product, qty > 0, rate >= 0.', 'error');
            return;
        }
        ajaxPost(ENDPOINTS.add, {
            customer_id: state.customerId,
            product_id:  productId,
            qty:         qty,
            rate:        rate
        })
            .done(function (resp) {
                if (resp.status === 'success') {
                    toast(resp.message || 'Item added', 'success');
                    if (resp.cart) {
                        state.cart = resp.cart;
                        state.validation = resp.cart.validation || state.validation;
                        renderAll();
                        // R11: refresh the active tab's badge from the new cart
                        updateActiveTabBadge(
                            (resp.cart.items || []).length,
                            { softHold: state.softHold }
                        );
                    } else {
                        loadCart(state.customerId);
                    }
                    // Reset add form
                    $('#addProduct').val('').trigger('change');
                    $('#addQty').val(1);
                    $('#addRate').val('');
                    $('#rateHint').html('&nbsp;');
                    renderAvailability(null);

                    // R18: refocus the product search box so the cashier
                    // can immediately scan/type the next product without
                    // reaching for the mouse. Mirrors Legacy
                    // sales-create.js::resetProductEntry L632:
                    //   document.getElementById('productSearch')?.focus();
                    // Select2 needs 'open' to focus the search input —
                    // just triggering focus on the original <select>
                    // doesn't bring up the search box.
                    setTimeout(function () {
                        $('#addProduct').select2('open');
                        // Select2's open gives focus to the search input
                        // automatically; no further focus call needed.
                    }, 50);
                } else {
                    toast(resp.message || 'Could not add item.', 'error');
                }
            })
            .fail(function (xhr) {
                toast('Add failed: ' + (xhr.responseJSON?.message || xhr.statusText), 'error');
            });
    }

    function updateItem(productId, qty, rate) {
        if (!state.customerId) return;
        if (!(qty > 0)) { toast('Qty must be > 0.', 'error'); return; }
        var payload = {
            customer_id: state.customerId,
            product_id:  productId,
            qty:         qty
        };
        if (rate !== null && rate !== undefined && !isNaN(rate)) payload.rate = rate;

        ajaxPost(ENDPOINTS.update, payload)
            .done(function (resp) {
                if (resp.status === 'success' && resp.cart) {
                    state.cart = resp.cart;
                    state.validation = resp.cart.validation || state.validation;
                    renderAll();
                    // R11: qty/rate change doesn't change item count, but
                    // refresh anyway in case the server merged a duplicate.
                    updateActiveTabBadge(
                        (resp.cart.items || []).length,
                        { softHold: state.softHold }
                    );
                } else {
                    toast(resp.message || 'Update failed.', 'error');
                }
            })
            .fail(function (xhr) {
                toast('Update failed: ' + (xhr.responseJSON?.message || xhr.statusText), 'error');
                loadCart(state.customerId); // resync on failure
            });
    }

    function removeItem(productId) {
        if (!state.customerId) return;
        Swal.fire({
            title: 'Remove item?',
            text: 'This will remove the product from the cart.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, remove it'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            ajaxPost(ENDPOINTS.remove, { customer_id: state.customerId, product_id: productId })
                .done(function (resp) {
                    if (resp.status === 'success') {
                        toast(resp.message || 'Item removed', 'success');
                        if (resp.cart) {
                            state.cart = resp.cart;
                            state.validation = resp.cart.validation || state.validation;
                            renderAll();
                            // R11: refresh the active tab's badge — count
                            // just dropped by 1.
                            updateActiveTabBadge(
                                (resp.cart.items || []).length,
                                { softHold: state.softHold }
                            );
                        } else {
                            loadCart(state.customerId);
                        }
                    } else {
                        toast(resp.message || 'Remove failed.', 'error');
                    }
                })
                .fail(function (xhr) {
                    toast('Remove failed: ' + (xhr.responseJSON?.message || xhr.statusText), 'error');
                });
        });
    }

    function clearCart() {
        if (!state.customerId) return;
        Swal.fire({
            title: 'Clear entire cart?',
            text: 'All items will be removed. This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, clear it'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            ajaxPost(ENDPOINTS.clear, { customer_id: state.customerId })
                .done(function (resp) {
                    if (resp.status === 'success') {
                        toast(resp.message || 'Cart cleared', 'success');
                        state.softHold = false;
                        loadCart(state.customerId);
                        // R11: after clear, the cart is empty — remove its tab.
                        // (list-drafts skips empty carts, so the tab would
                        // disappear on next refresh anyway; doing it now
                        // gives immediate visual feedback.)
                        removeTab(state.customerId);
                        // If no tabs remain, hide the dock + reset to empty state.
                        if ($('#draftTabs > li').length === 0) {
                            $('#draftTabsCard').addClass('d-none');
                        }
                    } else {
                        toast(resp.message || 'Clear failed.', 'error');
                    }
                })
                .fail(function (xhr) {
                    toast('Clear failed: ' + (xhr.responseJSON?.message || xhr.statusText), 'error');
                });
        });
    }

    function validateCart() {
        if (!state.customerId) { toast('Select a customer first.', 'error'); return; }
        ajaxPost(ENDPOINTS.validate, { customer_id: state.customerId })
            .done(function (validation) {
                state.validation = validation;
                renderValidation(validation);
                var btnFinalize = document.getElementById('btnFinalize');
                var hasItems = state.cart && state.cart.items && state.cart.items.length > 0;
                btnFinalize.disabled = !(hasItems && validation.valid);
                if (validation.valid) {
                    toast('Cart is valid — ready to finalize.', 'success');
                } else {
                    toast(validation.message || 'Cart has issues.', 'error');
                }
            })
            .fail(function (xhr) {
                toast('Validation failed: ' + (xhr.responseJSON?.message || xhr.statusText), 'error');
            });
    }

    function toggleSoftHold() {
        if (!state.customerId) return;
        var next = !state.softHold;
        ajaxPost(ENDPOINTS.softHold, { customer_id: state.customerId, soft_hold: next })
            .done(function (resp) {
                if (resp.status === 'success') {
                    state.softHold = next;
                    renderSummary();
                    toast(resp.message || 'Soft-hold updated', 'success');
                } else {
                    toast(resp.message || 'Soft-hold failed.', 'error');
                }
            })
            .fail(function (xhr) {
                toast('Soft-hold failed: ' + (xhr.responseJSON?.message || xhr.statusText), 'error');
            });
    }

    function checkAvailability(productId) {
        if (!productId) {
            renderAvailability(null);
            return;
        }
        ajaxGet(ENDPOINTS.availability, { product_id: productId })
            .done(function (payload) {
                state.availBreakdown = payload.warehouse_breakdown || [];
                state.availProductId = productId;
                renderAvailability(payload);
            })
            .fail(function (xhr) {
                toast('Availability check failed: ' + (xhr.responseJSON?.message || xhr.statusText), 'error');
                renderAvailability(null);
            });
    }

    // -------- Debounced update for table inputs --------
    // Works for BOTH desktop <tr> rows AND mobile .sales-cart-line
    // cards — looks up by data-product-id on any element.
    function debouncedUpdate(productId, field, value) {
        if (state.debounceTimers[productId]) {
            clearTimeout(state.debounceTimers[productId]);
        }
        state.debounceTimers[productId] = setTimeout(function () {
            // Find whichever view (desktop or mobile) is currently
            // holding the inputs for this product. Both views share
            // the same .cart-qty / .cart-rate classes, so we just
            // grab the first matching container.
            var $any = $('[data-product-id="' + productId + '"]').first();
            if (!$any.length) return;
            var qty  = parseFloat($any.find('.cart-qty').val());
            var rate = parseFloat($any.find('.cart-rate').val());
            updateItem(productId, qty, rate);
        }, 300);
    }

    // ============================================================
    // ============== R15: CUSTOMER RECENTS CHIPS =================
    // ============================================================
    //
    // Mirrors Legacy `rememberCustomerRecent` + `renderCustomerRecents`
    // (sales.js L1306–1354). Stores the last 5 picked customers in
    // localStorage and renders them as click-to-pick chips beneath
    // the customer Select2.
    //
    // Clicking a chip re-selects that customer via the R11
    // `switchToCustomer()` flow (Select2 value + tab ensure + cart
    // load + credit fetch). This is a meaningful UX win for
    // repeat-customer workflows: the cashier doesn't have to re-type
    // the name of a customer they just served 5 minutes ago.
    //
    // Storage key is namespaced to this app so a multi-tenant deploy
    // doesn't cross-contaminate. Shape:
    //   [{id:int, label:string, ts:int(unix_ms)}, ...]
    // Capped at 5, deduped by id, most-recent-first.

    var CUSTOMER_RECENTS_KEY = 'rcerp_sales_customer_recents';
    var CUSTOMER_RECENTS_MAX = 5;

    function rememberCustomerRecent(customerId, label) {
        if (!customerId) return;
        customerId = parseInt(customerId, 10);
        var text = (label || '').trim() || ('Customer #' + customerId);
        var recents = loadCustomerRecents();
        // Dedup by id (move to top)
        recents = recents.filter(function (r) {
            return parseInt(r.id, 10) !== customerId;
        });
        recents.unshift({ id: customerId, label: text, ts: Date.now() });
        recents = recents.slice(0, CUSTOMER_RECENTS_MAX);
        try {
            localStorage.setItem(CUSTOMER_RECENTS_KEY, JSON.stringify(recents));
        } catch (e) {
            // localStorage may be unavailable (private mode, quota).
            // Non-fatal — chips just won't persist across sessions.
            console.warn('Could not persist customer recents', e);
        }
    }

    function loadCustomerRecents() {
        try {
            var raw = localStorage.getItem(CUSTOMER_RECENTS_KEY);
            var arr = raw ? JSON.parse(raw) : [];
            return Array.isArray(arr) ? arr : [];
        } catch (e) {
            return [];
        }
    }

    function renderCustomerRecents() {
        var $row = $('#customerRecentsRow');
        var $box = $('#customerRecents');
        if (!$row.length || !$box.length) return;
        var recents = loadCustomerRecents();
        if (!recents.length) {
            $row.addClass('d-none');
            $box.empty();
            return;
        }
        $row.removeClass('d-none');
        $box.empty();
        recents.forEach(function (r) {
            // Prefer the in-memory customerCache label (richer —
            // includes shop_name + mobile) if available; fall back
            // to the stored label.
            var cached = customerCache[r.id];
            var label = cached ? tabLabelFor(r.id) : (r.label || ('#' + r.id));
            var $chip = $(
                '<button type="button" class="btn btn-sm" data-customer-id="' + parseInt(r.id, 10) + '" ' +
                    'title="' + escHtml(label) + '">' +
                    '<i class="fas fa-user me-1"></i>' + escHtml(label) +
                '</button>'
            );
            $box.append($chip);
        });
    }

    // ============================================================
    // ============== R16: STICKY BOTTOM BAR ======================
    // ============================================================
    //
    // Mirrors Legacy `updatePosStickyBar` (sales.js L1363–1380) +
    // `initPosStickyBar` (L1356–1361). Shows item count + grand
    // total + Finalize button in a fixed bottom bar so the cashier
    // never has to scroll to finalize.
    //
    // Visibility rules:
    //   - Cart empty or no customer → bar hidden, button disabled
    //   - Cart with items but invalid → bar visible, button disabled
    //     (clicking shows a "validate first" toast)
    //   - Cart with items + valid → bar visible, button enabled
    //
    // The bar's Finalize button calls the SAME `finalizeInvoice()`
    // function as the in-page #btnFinalize — same idempotency token
    // flow, same credit-check gate, same SweetAlert dialog.

    function updatePosStickyBar() {
        var $bar = $('#posStickyBar');
        var $summary = $('#posStickySummary');
        var $btn = $('#posStickyFinalize');
        if (!$bar.length) return;

        var items = (state.cart && state.cart.items) ? state.cart.items : [];
        var itemCount = items.length;
        var subtotal = state.cart ? (parseFloat(state.cart.subtotal) || 0) : 0;
        var valid = state.validation ? !!state.validation.valid : false;

        if (itemCount > 0 && state.customerId) {
            $bar.addClass('visible');
            // Toggle the page-padding class for browsers without :has()
            document.body.classList.add('pos-sticky-visible');
            $summary.html(
                '<span class="sticky-count">' + itemCount + '</span>' +
                '<span class="text-muted ms-1">' + (itemCount === 1 ? 'item' : 'items') + '</span>' +
                '<span class="mx-2 text-muted">·</span>' +
                '<span class="sticky-total">৳' + fmtMoney(subtotal) + '</span>'
            );
            // Button enabled iff cart is valid (mirrors #btnFinalize).
            $btn.prop('disabled', !valid);
            if (!valid) {
                $btn.attr('title', 'Click "Validate Cart" first — cart has stock or rate issues.');
            } else {
                $btn.attr('title', 'Create a draft sales invoice from this cart');
            }
        } else {
            $bar.removeClass('visible');
            document.body.classList.remove('pos-sticky-visible');
            $summary.html('<span class="text-muted small">No active cart</span>');
            $btn.prop('disabled', true);
        }
    }

    // ============================================================
    // ============== R17: MOBILE SWIPE-TO-DELETE =================
    // ============================================================
    //
    // Mirrors Legacy `initCartSwipeRemove` (sales.js L1422–1434):
    // on each .sales-cart-line card, record touchstart X and on
    // touchend compute the delta. If the user swiped left by more
    // than 80px, trigger the .cart-remove button's click handler
    // (which calls the existing removeItem() → SweetAlert confirm
    // → server call).
    //
    // Also adds a "swiping" CSS class during the gesture so the
    // card visibly slides left and reveals the red delete backdrop
    // (the ::before pseudo-element defined in the @@push('css') block).
    //
    // Re-bound after every renderCartTable() call (touch handlers
    // don't survive $mobile.empty()).

    function initCartSwipeRemove() {
        var $cards = $('#cartItemsMobile .sales-cart-line');
        if (!$cards.length) return;
        // Modern pointer events (covers touch + mouse + pen).
        $cards.off('pointerdown.swipe pointermove.swipe pointerup.swipe pointercancel.swipe');
        $cards.each(function () {
            var card = this;
            var startX = 0, startedAt = 0, dragging = false;
            card.addEventListener('pointerdown', function (e) {
                // Only respond to touch/pen primary inputs (not mouse).
                if (e.pointerType === 'mouse') return;
                startX = e.clientX;
                startedAt = Date.now();
                dragging = true;
            }, { passive: true });
            card.addEventListener('pointermove', function (e) {
                if (!dragging) return;
                var delta = e.clientX - startX;
                if (delta < 0 && delta > -120) {
                    card.classList.add('swiping');
                    card.style.transform = 'translateX(' + delta + 'px)';
                } else if (delta >= 0) {
                    card.classList.remove('swiping');
                    card.style.transform = '';
                }
            }, { passive: true });
            card.addEventListener('pointerup', function (e) {
                if (!dragging) return;
                dragging = false;
                var delta = e.clientX - startX;
                var elapsed = Date.now() - startedAt;
                card.classList.remove('swiping');
                card.style.transform = '';
                // 80px left swipe within 600ms = delete (mirrors Legacy).
                if (delta < -80 && elapsed < 600) {
                    var $btn = $(card).find('.cart-remove');
                    if ($btn.length) $btn.trigger('click');
                }
            }, { passive: true });
            card.addEventListener('pointercancel', function () {
                dragging = false;
                card.classList.remove('swiping');
                card.style.transform = '';
            }, { passive: true });
        });
    }

    // ============================================================
    // ============== EVENT WIRING (DOM ready) ====================
    // ============================================================
    $(function () {
        // --- Initialize Select2 ---
        // R1: customer & product dropdowns are now AJAX-driven (live search).
        // The 500-row server-side pre-render has been removed.
        $('#customerSelect').select2({
            theme: 'bootstrap-5',
            placeholder: '— Type customer name / code / mobile —',
            allowClear: true,
            minimumInputLength: 1,
            ajax: {
                url: ENDPOINTS.searchCustomer,
                dataType: 'json',
                delay: 250,                       // debounce (ms) — matches Legacy UX
                data: function (params) {
                    return { term: (params.term || '').trim() };
                },
                processResults: function (data) {
                    return {
                        results: (data || []).map(function (c) {
                            var label = c.customer_name || c.shop_name || ('#' + c.id);
                            if (c.customer_code)  label += ' [' + c.customer_code + ']';
                            if (c.mobile)         label += ' · ' + c.mobile;
                            // R11: cache the customer so the tab dock can
                            // render the right label without an extra fetch.
                            customerCache[c.id] = {
                                id: c.id,
                                customer_code: c.customer_code,
                                customer_name: c.customer_name,
                                shop_name: c.shop_name,
                                mobile: c.mobile,
                                credit_limit: c.credit_limit,
                            };
                            return {
                                id: c.id,
                                text: label,
                                // stash for client-side use
                                customer_code: c.customer_code,
                                customer_name: c.customer_name,
                                shop_name: c.shop_name,
                                mobile: c.mobile,
                                credit_limit: c.credit_limit,
                            };
                        })
                    };
                },
                cache: true,
            },
            templateSelection: function (state) {
                if (!state.id) return state.text;
                // Preserve the server-rendered "Customer [CODE]" label for the
                // pre-selected customer; for AJAX picks, use the formatted text.
                return state.text || state.customer_name || ('#' + state.id);
            },
        });

        $('#addProduct').select2({
            theme: 'bootstrap-5',
            placeholder: '— Type name / scan code —',
            allowClear: true,
            minimumInputLength: 1,
            // Barcode-scanner-friendly: when the dropdown closes (e.g.
            // scanner sends Enter), pick the highlighted first result
            // automatically. Combined with the AJAX search matching on
            // product_code (R1), this makes the single Select2 box
            // double as the barcode entry — no separate input needed.
            selectOnClose: true,
            ajax: {
                url: ENDPOINTS.searchProduct,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return { term: (params.term || '').trim(), branch_id: BRANCH_ID };
                },
                processResults: function (data) {
                    return {
                        results: (data || []).map(function (p) {
                            // Cache the full product payload so the change
                            // handler can read default_rate/min_rate/max_rate
                            // without another round-trip.
                            productCache[p.id] = p;
                            var label = p.product_name + (p.product_code ? ' [' + p.product_code + ']' : '');
                            return {
                                id: p.id,
                                text: label,
                                // also stashed on the option for back-compat
                                'data-default-rate': p.default_rate,
                                'data-code': p.product_code,
                                'data-name': p.product_name,
                            };
                        })
                    };
                },
                cache: true,
            },
        });

        // --- Tooltips ---
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (el) { return new bootstrap.Tooltip(el); });

        // --- Customer change ---
        $('#customerSelect').on('change', function () {
            var cid = $(this).val();
            if (cid) {
                // Update URL so refresh preserves selection
                if (window.history && history.replaceState) {
                    var newUrl = window.location.pathname + '?customer_id=' + parseInt(cid, 10);
                    history.replaceState(null, '', newUrl);
                }
                // R11: ensure a tab exists for this customer before loadCart
                // fires (so the pill appears immediately, even before the
                // cart load resolves).
                ensureTab(cid, { active: true, label: tabLabelFor(cid) });
                loadCart(cid);
                // R14: fetch the live credit snapshot for this customer so
                // the credit panel renders alongside the cart. Cheap call
                // (one indexed SUM query), throttled 60/min — only fires
                // on customer change, not on every cart mutation.
                fetchCustomerDetails(parseInt(cid, 10));
                // R15: remember this customer in localStorage so a chip
                // appears under the Select2 for one-click re-selection
                // later. Use the Select2-rendered label (includes shop +
                // mobile + code) so the chip is meaningful at a glance.
                var label = ($('#customerSelect option:selected').text() || '').trim()
                    || tabLabelFor(parseInt(cid, 10));
                rememberCustomerRecent(cid, label);
                renderCustomerRecents();
            } else {
                if (window.history && history.replaceState) {
                    history.replaceState(null, '', window.location.pathname);
                }
                loadCart(null);
                // R14: hide the credit panel — no customer selected.
                fetchCustomerDetails(null);
                // R16: hide the sticky bar — no customer selected.
                updatePosStickyBar();
            }
        });

        // R15: chip click → switch to that customer (reuses R11 flow).
        $(document).on('click', '#customerRecents .btn[data-customer-id]', function (e) {
            e.preventDefault();
            var cid = parseInt($(this).data('customer-id'), 10);
            if (!cid) return;
            if (parseInt(state.customerId, 10) === cid) return; // already active
            switchToCustomer(cid);
        });

        $('#btnLoadCart').on('click', function () {
            loadCart($('#customerSelect').val());
        });

        // R14: "Refresh" button — re-fetch the credit snapshot in case a
        // payment was posted in another tab/window since the customer was
        // first selected. Useful for long-running cart sessions.
        $('#btnRefreshCredit').on('click', function () {
            if (!state.customerId) return;
            var $btn = $(this);
            var $icon = $btn.find('i');
            $icon.addClass('fa-spin');
            ajaxGet(ENDPOINTS.customerDetails, { customer_id: state.customerId })
                .done(function (data) {
                    state.customerCredit = data || null;
                    renderCustomerDetails();
                })
                .fail(function () {
                    toast('Could not refresh credit snapshot.', 'error');
                })
                .always(function () {
                    $icon.removeClass('fa-spin');
                });
        });

        // --- Add Product interactions ---
        $('#addProduct').on('change', function () {
            var productId = parseInt($(this).val(), 10);

            if (!productId) {
                $('#addRate').val('');
                $('#rateHint').html('&nbsp;');
                // R13: clear the price band when no product is selected.
                setActivePriceRange(null);
                renderAvailability(null);
                return;
            }

            // R1: prefer the in-memory productCache (populated by the AJAX
            // search) — it has min_rate/max_rate/default_rate/available_qty.
            // Fall back to <option> data attributes for back-compat.
            var p = productCache[productId] || {};
            var defaultRate = p.default_rate !== undefined
                ? p.default_rate
                : ($(this).find('option:selected').data('default-rate') || 0);

            // Auto-fill rate with default_rate
            $('#addRate').val(parseFloat(defaultRate).toFixed(2));

            // Rate hint — show min/max range when available (from live search),
            // otherwise fall back to default-rate only.
            var hint;
            if (p.min_rate !== undefined && p.max_rate !== undefined &&
                (parseFloat(p.min_rate) > 0 || parseFloat(p.max_rate) > 0)) {
                hint = 'Default ' + fmtMoney(defaultRate) +
                       ' · Range ' + fmtMoney(p.min_rate) + '–' + fmtMoney(p.max_rate);
            } else {
                hint = 'Default: ' + fmtMoney(defaultRate);
            }
            $('#rateHint').html('<i class="fas fa-info-circle me-1"></i>' + hint);

            // R13: prime the price-range slider band with this product's
            // min/max/default. setActivePriceRange() also hides the band
            // if the product has no usable range (min<=0 or max<=0).
            setActivePriceRange(p);

            // Check availability (branch-wide). If the live-search payload
            // already includes available_qty, prime the card immediately so
            // the user sees stock info even before the breakdown request
            // resolves.
            if (p.available_qty !== undefined) {
                $('#addAvailTotal').text(fmtQty(p.available_qty) + ' (live)');
            }
            checkAvailability(productId);

            // R18: auto-focus #addQty + select its content so the cashier
            // can immediately type a new qty (Legacy mirrors this in
            // sales-create.js::selectProductCreate). Default of 1 is
            // already in the field — selecting it means Enter accepts 1,
            // or typing a new number replaces it.
            var $qty = $('#addQty');
            $qty.focus();
            $qty[0] && $qty[0].select();
        });

        // R13: live rate changes should reposition the slider thumb and
        // refresh the in-range / warn / bad status badge. Debounced
        // 60ms so rapid typing doesn't thrash the DOM.
        var _rateBandDebounce = null;
        $('#addRate').on('input change', function () {
            if (_rateBandDebounce) clearTimeout(_rateBandDebounce);
            _rateBandDebounce = setTimeout(updatePriceBandUi, 60);
        });

        // R13: "Use default" button — snap the rate back to default_rate
        // and re-render the band. Mirrors Legacy #btnUseDefaultRate in
        // sales/create.php L104–106.
        $('#btnUseDefaultRate').on('click', function () {
            if (!state.activePriceRange) return;
            var def = parseFloat(state.activePriceRange.default_rate) || 0;
            $('#addRate').val(def.toFixed(2)).trigger('change');
            toast('Rate reset to default (৳' + fmtMoney(def) + ')', 'info');
        });

        $('#btnAddToCart').on('click', addToCart);

        // ============================================================
        // ============== R18: KEYBOARD SHORTCUTS =====================
        // ============================================================
        // Mirrors Legacy sales-create.js keyboard flow:
        //   1. Product Select2 ArrowUp/ArrowDown/Enter — already
        //      handled natively by Select2 (dropdown navigation).
        //      R10s added the Enter-on-empty fallback to the
        //      productByCode endpoint.
        //   2. After a product is picked (Select2 change), auto-focus
        //      #addQty and select its content so the cashier can
        //      immediately type a new qty without an extra Tab.
        //      (Legacy: sales-create.js::selectProductCreate calls
        //      document.getElementById('quantity')?.focus() — though
        //      Legacy also pre-fills qty=1, the focus lets the cashier
        //      override by just typing.)
        //   3. Enter in #addQty → focus + select #addRate (NOT submit).
        //      Legacy sales-create.js L615–621: Enter on quantity
        //      moves focus to rate, so the cashier can review/override
        //      the rate before submitting. This is critical for
        //      keyboard-only operation — without it, the cashier would
        //      have to Tab to rate or reach for the mouse.
        //   4. Enter in #addRate → click "Add to Cart" (addToCart).
        //      Legacy sales-create.js L96–100: Enter on rate triggers
        //      the addToCartBtn click. After add, focus returns to the
        //      product search so the cashier can scan/type the next
        //      product immediately (Legacy sales-create.js::resetProductEntry
        //      L632: document.getElementById('productSearch')?.focus()).
        $('#addQty').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                // Move focus to rate and select its content so the
                // cashier can immediately type a new value or press
                // Enter again to accept the default and add to cart.
                var $rate = $('#addRate');
                $rate.focus();
                $rate[0] && $rate[0].select();
            }
        });
        $('#addRate').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addToCart();
            }
        });

        // ============================================================
        // ============ BARCODE SCANNER (simplified) ==================
        // ============================================================
        // Barcode scanners act as HID keyboards: they "type" the code
        // rapidly and end with Enter. The Select2 search box is the
        // single product entry — there is no separate barcode input
        // any more (R10's dual-mode UI was removed because it
        // duplicated the search box and made the page feel cluttered).
        //
        // Two layers of barcode support:
        //
        //   1. The AJAX search already matches on product_code via
        //      ILIKE (R1), so most scans resolve as the user types:
        //      Select2 shows matching results, the first one is
        //      auto-highlighted, and `selectOnClose: true` (set in
        //      the Select2 init above) makes Enter pick it.
        //
        //   2. FALLBACK: if the user types/scans a code that returns
        //      NO matches from the ILIKE search (rare — only happens
        //      when product_code has trailing/leading whitespace
        //      differences or the user types a code that's a SUBSTRING
        //      of nothing), we intercept Enter on the Select2 search
        //      input and fire an exact-match lookup against the R1
        //      productByCode endpoint. On success, the matched product
        //      is injected as a fresh <option> + auto-selected.
        //
        // The fallback is delegated (works even though Select2
        // re-creates the search input on every open).
        $(document).on('keydown', '.select2-search__field', function (e) {
            if (e.key !== 'Enter') return;
            // Only intercept when the open dropdown belongs to #addProduct
            var $dropdown = $(this).closest('.select2-dropdown');
            if (!$dropdown.length) return;
            var $container = $dropdown.prev('.select2');
            // The dropdown's corresponding container sits in the DOM
            // just before .select2-dropdown; verify it wraps #addProduct.
            // Simpler: check whether the original <select> is #addProduct
            // by walking up to the body and finding which Select2 is open.
            // We use the aria-controls attribute on the search input,
            // which Select2 sets to the id of the results container,
            // which is namespaced by the original select id.
            var resultsId = $(this).attr('aria-controls') || '';
            if (resultsId.indexOf('addProduct') === -1
                && resultsId.indexOf('select2-addProduct') === -1) {
                // Not our product search — let Select2 handle it normally.
                return;
            }
            // If a result is highlighted, let Select2 do its default
            // (select + close). We only fall back to productByCode
            // when nothing is highlighted.
            var $highlighted = $dropdown.find('.select2-results__option--highlighted');
            if ($highlighted.length) return;
            // No highlighted result → take the typed term as a code.
            var term = ($(this).val() || '').trim();
            if (!term) return;
            e.preventDefault();
            lookupProductByCodeAndSelect(term);
        });

        /**
         * Fallback exact-code lookup: fires when the user pressed Enter
         * in the product Select2 search box with no highlighted result.
         * Calls the R1 productByCode endpoint and on success injects
         * the matched product as a fresh <option> + triggers the
         * normal change handler (which fills rate + price band +
         * availability). Does NOT auto-add to cart — the cashier can
         * review the populated rate/qty first, then click "Add".
         */
        function lookupProductByCodeAndSelect(code) {
            if (!state.customerId) {
                toast('Select a customer first.', 'error');
                return;
            }
            // Close the Select2 dropdown so the user sees the form.
            $('#addProduct').select2('close');
            // Brief hint while the lookup runs (no separate input to
            // write to — surface via toast + the existing #rateHint).
            $('#rateHint').html('<i class="fas fa-spinner fa-spin me-1"></i>Looking up code "' + escHtml(code) + '"…');
            ajaxGet(ENDPOINTS.productByCode, { code: code, branch_id: BRANCH_ID })
                .done(function (json) {
                    if (!json || json.status !== 'success' || !json.data) {
                        $('#rateHint').html('<i class="fas fa-times-circle me-1 text-danger"></i>No product with code "' + escHtml(code) + '".');
                        toast('No product with code ' + code, 'warning');
                        // Reopen Select2 so the user can re-search.
                        setTimeout(function () { $('#addProduct').select2('open'); }, 50);
                        return;
                    }
                    var p = json.data;
                    // Cache so the change handler / availability renderer sees it
                    productCache[p.id] = p;
                    // Inject a fresh <option> and select it
                    var label = p.product_name + (p.product_code ? ' [' + p.product_code + ']' : '');
                    var $newOpt = $('<option></option>')
                        .val(p.id)
                        .text(label)
                        .data('default-rate', p.default_rate)
                        .data('code', p.product_code)
                        .data('name', p.product_name);
                    $('#addProduct').append($newOpt).val(p.id).trigger('change');
                    // Stock guard hint
                    var avail = parseFloat(p.available_qty || 0);
                    if (avail <= 0) {
                        toast('Out of stock: ' + (p.product_name || code), 'warning');
                    }
                    // Pre-fill rate (default_rate, fall back to min_rate)
                    var defRate = parseFloat(p.default_rate);
                    if (!(defRate > 0)) defRate = parseFloat(p.min_rate) || 0;
                    $('#addRate').val(defRate.toFixed(2));
                    setActivePriceRange(p);  // R13: snap the slider thumb
                    $('#addQty').val(1).focus().select();
                    toast('Ready: ' + (p.product_name || code) + ' · ৳' + fmtMoney(defRate), 'success');
                })
                .fail(function (xhr) {
                    $('#rateHint').html('<i class="fas fa-exclamation-circle me-1 text-danger"></i>Lookup failed: ' + escHtml(xhr.statusText || 'network error'));
                    toast('Lookup failed', 'error');
                });
        }

        // --- Cart table inline edits (desktop + mobile share classes) ---
        $(document).on('input change', '.cart-qty, .cart-rate', function () {
            // Works for BOTH desktop <tr> rows and mobile .sales-cart-line
            // cards — both use .cart-qty / .cart-rate inputs.
            var $container = $(this).closest('[data-product-id]');
            var productId = parseInt($container.data('product-id'), 10);

            // Optimistic local update of total cell(s) — update every
            // view of this product so desktop + mobile stay in sync.
            var qty  = parseFloat($container.find('.cart-qty').val()) || 0;
            var rate = parseFloat($container.find('.cart-rate').val()) || 0;
            $('[data-product-id="' + productId + '"]').each(function () {
                $(this).find('.cart-total').text(fmtMoney(qty * rate));
            });

            // Debounced server update
            debouncedUpdate(productId);
        });

        $(document).on('click', '.cart-remove', function () {
            // Works for both desktop <tr> and mobile .sales-cart-line cards.
            var productId = parseInt($(this).closest('[data-product-id]').data('product-id'), 10);
            removeItem(productId);
        });

        // --- Cart action buttons ---
        $('#btnClear').on('click', clearCart);
        $('#btnSoftHold').on('click', toggleSoftHold);
        $('#btnValidate').on('click', validateCart);
        $('#btnFinalize').on('click', function () {
            finalizeInvoice();
        });
        // R16: sticky-bar Finalize button mirrors #btnFinalize.
        $('#posStickyFinalize').on('click', function () {
            if (this.disabled) return;
            finalizeInvoice();
        });

        // ============================================================
        // ============== FINALIZE INVOICE FLOW (P0-6) ================
        // ============================================================
        //
        // Multi-step flow:
        //   1. Pre-flight: cart must be non-empty + validated.
        //   2. Open a SweetAlert "Finalize Invoice" dialog with editable
        //      fields (invoice_date, discount, transport, notes, override).
        //   3. On confirm: pre-check credit limit via GET /credit-check.
        //      If exceeded and user didn't tick override → re-prompt.
        //   4. POST /finalize with all fields.
        //   5. On success: redirect to the new invoice's show page.
        //      On error: show SweetAlert error, re-enable button.
        //
        // The button is disabled during the AJAX request to mitigate
        // double-submit (P2-6 will add a proper idempotency token).

        function finalizeInvoice() {
            // --- Step 1: pre-flight checks ---
            if (!state.cart || !state.cart.items || state.cart.items.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Cart is empty',
                    text: 'Add at least one product before finalizing.',
                    confirmButtonColor: '#7c3aed'
                });
                return;
            }

            var valid = state.validation ? !!state.validation.valid : false;
            if (!valid) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Cart validation failed',
                    text: 'Click "Validate Cart" and resolve any stock/rate errors before finalizing.',
                    confirmButtonColor: '#7c3aed'
                });
                return;
            }

            var customerId = state.customerId;
            if (!customerId) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No customer selected',
                    text: 'Please select a customer first.',
                    confirmButtonColor: '#7c3aed'
                });
                return;
            }

            var subtotal = parseFloat((state.cart && state.cart.subtotal) || 0);
            var today = new Date().toISOString().split('T')[0];

            // P2-6: Generate idempotency token (UUID v4) for this finalize attempt.
            // Prevents duplicate invoice creation on double-click or refresh-after-submit.
            var idempotencyToken = (function() {
                if (window.crypto && window.crypto.randomUUID) {
                    return window.crypto.randomUUID();
                }
                // Fallback UUID v4 generator (older browsers).
                return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                    var r = Math.random() * 16 | 0;
                    var v = c === 'x' ? r : (r & 0x3 | 0x8);
                    return v.toString(16);
                });
            })();

            // --- Step 2: open the finalize dialog ---
            Swal.fire({
                title: '<i class="fas fa-file-invoice-dollar me-2"></i>Finalize Invoice',
                html:
                    '<div class="text-start">' +
                    '<div class="mb-2"><label class="form-label small fw-semibold">Invoice Date</label>' +
                    '<input type="date" id="finInvoiceDate" class="form-control form-control-sm" value="' + today + '"></div>' +

                    '<div class="row g-2 mb-2">' +
                    '<div class="col-6"><label class="form-label small fw-semibold">Discount (Tk)</label>' +
                    '<input type="number" id="finDiscount" class="form-control form-control-sm" min="0" step="0.01" value="0"></div>' +
                    '<div class="col-6"><label class="form-label small fw-semibold">Transport (Tk)</label>' +
                    '<input type="number" id="finTransport" class="form-control form-control-sm" min="0" step="0.01" value="0"></div>' +
                    '</div>' +

                    '<div class="mb-2"><label class="form-label small fw-semibold">Sales Person (optional)</label>' +
                    '<input type="text" id="finSalesPerson" class="form-control form-control-sm" maxlength="100" placeholder="Free-text name"></div>' +

                    '<div class="mb-2"><label class="form-label small fw-semibold">Dispatchers</label>' +
                    '<select id="finDispatchers" class="form-select form-select-sm select2" multiple data-placeholder="Select dispatchers…">' +
                    '<option value="" disabled>Loading…</option></select>' +
                    '<div class="small text-muted mt-1">Assign delivery personnel for this invoice.</div></div>' +

                    '<div class="mb-2"><label class="form-label small fw-semibold">Notes (optional)</label>' +
                    '<textarea id="finNotes" class="form-control form-control-sm" rows="2" maxlength="1000"></textarea></div>' +

                    '<div class="form-check mb-2">' +
                    '<input type="checkbox" id="finSoftHold" class="form-check-input">' +
                    '<label for="finSoftHold" class="form-check-label small">Mark as soft-hold (awaiting godown)</label>' +
                    '</div>' +

                    '<div class="form-check mb-1">' +
                    '<input type="checkbox" id="finOverride" class="form-check-input">' +
                    '<label for="finOverride" class="form-check-label small text-warning">Override credit limit (if exceeded)</label>' +
                    '</div>' +
                    '<input type="text" id="finOverrideReason" class="form-control form-control-sm mb-2" maxlength="500" placeholder="Override reason (min 10 chars if overriding)" disabled>' +

                    '<hr class="my-2"><div class="d-flex justify-content-between">' +
                    '<span class="text-muted small">Subtotal</span>' +
                    '<span class="fw-semibold">Tk ' + fmtMoney(subtotal) + '</span>' +
                    '</div>' +
                    '<div class="d-flex justify-content-between" id="finTotalRow">' +
                    '<span class="text-muted small">Estimated Total</span>' +
                    '<span class="fw-bold text-primary" id="finTotal">Tk ' + fmtMoney(subtotal) + '</span>' +
                    '</div>' +
                    '</div>',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check me-1"></i> Finalize',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#7c3aed',
                cancelButtonColor: '#6b7280',
                width: 540,
                showLoaderOnConfirm: true,
                didOpen: function () {
                    // Wire up live total recalculation.
                    var $popup = $(Swal.getPopup());
                    var recalc = function () {
                        var d = parseFloat($popup.find('#finDiscount').val()) || 0;
                        var t = parseFloat($popup.find('#finTransport').val()) || 0;
                        var total = subtotal - d + t;
                        $popup.find('#finTotal').text('Tk ' + fmtMoney(total));
                    };
                    $popup.on('input', '#finDiscount, #finTransport', recalc);

                    // Toggle override reason field.
                    $popup.on('change', '#finOverride', function () {
                        $popup.find('#finOverrideReason').prop('disabled', !this.checked);
                        if (!this.checked) {
                            $popup.find('#finOverrideReason').val('');
                        }
                    });

                    // Load dispatchers for current branch via AJAX.
                    var $sel = $popup.find('#finDispatchers');
                    $sel.empty().append('<option value="" disabled>Loading…</option>');
                    $.get(ENDPOINTS.branchDispatchers, { branch_id: BRANCH_ID }, function (data) {
                        $sel.empty();
                        if (data && data.length) {
                            data.forEach(function (emp) {
                                $sel.append(
                                    '<option value="' + emp.id + '">' +
                                    escHtml(emp.name) + (emp.employee_code ? ' (' + escHtml(emp.employee_code) + ')' : '') +
                                    '</option>'
                                );
                            });
                        } else {
                            $sel.append('<option value="" disabled>No dispatchers available</option>');
                        }
                        // Initialize Select2 on the dynamic select.
                        $sel.select2({
                            width: '100%',
                            placeholder: 'Select dispatchers…',
                            allowClear: true,
                            dropdownParent: $popup,
                        });
                    }).fail(function () {
                        $sel.empty().append('<option value="" disabled>Failed to load dispatchers</option>');
                    });
                },
                preConfirm: function () {
                    var $popup = $(Swal.getPopup());
                    var invoiceDate = $popup.find('#finInvoiceDate').val();
                    var discount = parseFloat($popup.find('#finDiscount').val()) || 0;
                    var transport = parseFloat($popup.find('#finTransport').val()) || 0;
                    var salesPerson = $popup.find('#finSalesPerson').val().trim();
                    var notes = $popup.find('#finNotes').val().trim();
                    var isSoftHold = $popup.find('#finSoftHold').is(':checked');
                    var override = $popup.find('#finOverride').is(':checked');
                    var overrideReason = $popup.find('#finOverrideReason').val().trim();
                    var dispatcherIds = $popup.find('#finDispatchers').val() || [];

                    if (!invoiceDate) {
                        Swal.showValidationMessage('Invoice date is required.');
                        return false;
                    }
                    if (discount < 0 || transport < 0) {
                        Swal.showValidationMessage('Discount and transport cannot be negative.');
                        return false;
                    }
                    if (discount > subtotal + 0.01) {
                        Swal.showValidationMessage('Discount cannot exceed subtotal (Tk ' + fmtMoney(subtotal) + ').');
                        return false;
                    }
                    if (override && overrideReason.length < 10) {
                        Swal.showValidationMessage('Override reason must be at least 10 characters.');
                        return false;
                    }

                    var total = subtotal - discount + transport;

                    // Disable the finalize button during the async request.
                    var $btn = $('#btnFinalize').prop('disabled', true).html(
                        '<span class="spinner-border spinner-border-sm me-1"></span> Finalizing…'
                    );

                    // Step 3: pre-check credit limit.
                    return ajaxGet(ENDPOINTS.creditCheck, {
                        customer_id: customerId,
                        amount: total
                    }).then(function (credit) {
                        if (credit.exceeds && !override) {
                            Swal.showValidationMessage(
                                'Credit limit exceeded!\n\n' +
                                'Current balance: Tk ' + fmtMoney(credit.current_balance) + '\n' +
                                'Credit limit: Tk ' + fmtMoney(credit.credit_limit) + '\n' +
                                'New balance: Tk ' + fmtMoney(credit.new_balance) + '\n\n' +
                                'Tick "Override credit limit" and provide a reason (min 10 chars) to proceed.'
                            );
                            $btn.prop('disabled', false).html(
                                '<i class="fas fa-file-invoice-dollar me-1"></i> Finalize Invoice'
                            );
                            return false;
                        }

                        // Step 4: POST to finalize endpoint.
                        return ajaxPost(ENDPOINTS.finalize, {
                            customer_id: customerId,
                            branch_id: BRANCH_ID,
                            invoice_date: invoiceDate,
                            sales_person: salesPerson || null,
                            discount_amount: discount,
                            transport_cost: transport,
                            notes: notes,
                            is_soft_hold: isSoftHold,
                            credit_limit_override: override,
                            override_reason: override ? overrideReason : '',
                            idempotency_token: idempotencyToken,
                            dispatcher_ids: dispatcherIds
                        }).then(function (resp) {
                            return resp;
                        }).catch(function (xhr) {
                            var msg = (xhr.responseJSON && xhr.responseJSON.message)
                                ? xhr.responseJSON.message
                                : 'Finalize failed (HTTP ' + xhr.status + ').';
                            // Re-enable button on error.
                            $btn.prop('disabled', false).html(
                                '<i class="fas fa-file-invoice-dollar me-1"></i> Finalize Invoice'
                            );
                            Swal.showValidationMessage(msg);
                            return false;
                        });
                    }).catch(function (xhr) {
                        // Credit check itself failed — proceed anyway (fail-open).
                        var msg = (xhr.responseJSON && xhr.responseJSON.message)
                            ? xhr.responseJSON.message
                            : 'Credit check failed (HTTP ' + xhr.status + ').';
                        $btn.prop('disabled', false).html(
                            '<i class="fas fa-file-invoice-dollar me-1"></i> Finalize Invoice'
                        );
                        Swal.showValidationMessage('Credit check failed: ' + msg);
                        return false;
                    });
                }
            }).then(function (result) {
                // Step 5: handle success / cancel.
                if (result.isConfirmed && result.value && result.value.status === 'success') {
                    var resp = result.value;
                    Swal.fire({
                        icon: 'success',
                        title: 'Invoice Created',
                        html: '<strong>' + escHtml(resp.invoice_code) + '</strong><br>' +
                              '<span class="text-muted small">' + escHtml(resp.message) + '</span>',
                        confirmButtonColor: '#7c3aed',
                        confirmButtonText: '<i class="fas fa-eye me-1"></i> View Invoice',
                        showCancelButton: true,
                        cancelButtonText: 'Stay on Cart'
                    }).then(function (view) {
                        if (view.isConfirmed && resp.redirect) {
                            window.location.href = resp.redirect;
                        } else {
                            // Reset button + clear cart from UI (it was consumed).
                            $('#btnFinalize').prop('disabled', false).html(
                                '<i class="fas fa-file-invoice-dollar me-1"></i> Finalize Invoice'
                            );
                            // Reload the cart page to reflect the now-empty cart.
                            window.location.reload();
                        }
                    });
                } else {
                    // Re-enable button if dialog was cancelled or failed.
                    $('#btnFinalize').prop('disabled', false).html(
                        '<i class="fas fa-file-invoice-dollar me-1"></i> Finalize Invoice'
                    );
                }
            });
        }

        // --- Initial render ---
        if (state.cart) {
            setWorkspaceVisible(true);
            renderAll();
        } else {
            setWorkspaceVisible(false);
        }

        // ============================================================
        // ============== R11: bootstrap the multi-cart dock ==========
        // ============================================================
        // Wire delegated click handlers for pill bodies + × buttons.
        initDraftTabsDock();

        // Pre-populate customerCache with the server-rendered selected
        // customer (if any) so the first tab's label is correct before
        // any AJAX fires.
        @if (!empty($selectedCustomer))
            customerCache[{{ (int) $selectedCustomer->id }}] = {
                id: {{ (int) $selectedCustomer->id }},
                customer_code: @json((string) ($selectedCustomer->customer_code ?? '')),
                customer_name: @json((string) ($selectedCustomer->customer_name ?? '')),
                shop_name:     @json((string) ($selectedCustomer->shop_name ?? '')),
                mobile:        @json((string) ($selectedCustomer->mobile ?? '')),
            };
            // Render the initial tab immediately with whatever count we
            // already know (from the server-rendered cart payload).
            var initialCount = (state.cart && state.cart.items) ? state.cart.items.length : 0;
            ensureTab({{ (int) $selectedCustomer->id }}, {
                active: true,
                itemCount: initialCount,
                softHold: state.softHold,
                label: tabLabelFor({{ (int) $selectedCustomer->id }}),
            });
        @endif

        // Fetch the full list of open carts + render one pill per cart.
        // This is async — by the time it resolves, the initial tab (if
        // any) is already showing, and we just add the rest + activate
        // the right one.
        restoreSessionCarts();

        // ============================================================
        // ============== R14: bootstrap the credit snapshot ==========
        // ============================================================
        // If a customer is pre-selected (?customer_id=...), fetch their
        // credit snapshot immediately so the panel renders alongside
        // the initial server-rendered cart payload — no extra round-trip
        // beyond the one customer-details call. When the customer
        // changes later (via Select2), the change handler re-fetches.
        if (state.customerId) {
            fetchCustomerDetails(state.customerId);
            // R15: also remember the server-rendered customer in
            // localStorage so the chip is there on the very first
            // page load (not just on the next customer-pick).
            @if (!empty($selectedCustomer))
                rememberCustomerRecent(
                    {{ (int) $selectedCustomer->id }},
                    tabLabelFor({{ (int) $selectedCustomer->id }})
                );
            @endif
        }
        // R15: render the customer-recents chips from localStorage.
        renderCustomerRecents();

        // R16: render the sticky bottom bar from the initial cart
        // state. (updatePosStickyBar is also called from renderAll on
        // every cart mutation, so this just covers the initial paint.)
        updatePosStickyBar();
    });
})();
</script>
@endpush
