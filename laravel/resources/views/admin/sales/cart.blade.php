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
    // Dispatch-branch enhancement: load all active branches for the
    // dispatch-to dropdown in the cart meta grid + warehouse modal.
    $allBranches = \App\Models\Branch::active()->orderBy('branch_name')->get(['id', 'branch_name', 'branch_code']);
@endphp

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
                                <label class="form-label small">
                                    <i class="fas fa-truck me-1 text-info"></i>Dispatch to branch
                                </label>
                                <select id="branch_id" class="form-select">
                                    @foreach($allBranches as $b)
                                        <option value="{{ $b->id }}"
                                                {{ (int) $b->id === (int) $branchId ? 'selected' : '' }}
                                                data-branch-name="{{ $b->branch_name }}"
                                                data-branch-code="{{ $b->branch_code }}">
                                            {{ $b->branch_name }}
                                            @if($b->branch_code) ({{ $b->branch_code }})@endif
                                        </option>
                                    @endforeach
                                </select>
                                <small class="text-muted d-block mt-1">Invoice will appear on this branch's warehouse manager dashboard</small>
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
                        {{-- Stock availability banner (legacy L99 + image B parity).
                             Teal banner shows: "Available [dispatch branch badge] N
                             [Warehouse & pipeline button]". The button
                             opens a modal with per-warehouse stock +
                             pipeline amount breakdown for the selected dispatch branch. --}}
                        <div id="BranchStock" class="sales-stock-banner d-none">
                            <div class="stock-banner-inner">
                                <div class="stock-stat">
                                    <span class="stock-label">Available</span>
                                    <span id="addAvailBranchBadge" class="badge bg-dark bg-opacity-50 ms-1">—</span>
                                    <span class="stock-value" id="addAvailTotal">—</span>
                                </div>
                                <div class="d-flex align-items-center gap-2 ms-auto">
                                    <span id="dispatchBranchHint" class="badge bg-info bg-opacity-75 text-white" style="font-size:0.7rem">
                                        <i class="fas fa-truck me-1"></i><span id="dispatchBranchHintText">{{ $branchName }}</span>
                                    </span>
                                    <button type="button" id="btnWarehousePipeline" class="btn btn-sm btn-outline-light" title="Per-warehouse stock + pipeline breakdown for dispatch branch">
                                        <i class="fas fa-warehouse me-1"></i> Warehouse &amp; pipeline
                                    </button>
                                </div>
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
                                <thead class="table-dark">
                                    <tr>
                                        <th style="width:48px;">Sl</th>
                                        <th style="min-width:220px;">Product</th>
                                        <th class="text-end" style="width:110px;">Qty</th>
                                        <th class="text-end" style="width:140px;">Rate</th>
                                        <th class="text-end" style="width:120px;">Total</th>
                                        <th class="text-center" style="width:80px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="cartItemsBody"></tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="4" class="text-end">Subtotal</th>
                                        <th class="text-end" id="cartSubtotalCell">0.00</th>
                                        <th></th>
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

                {{-- Validation banner (image C parity — pink "Cannot finalize
                     until fixed: ..." alert). Mirrors legacy
                     .sales-cart-invalid-banner (sales.js L177-181).
                     Populated by JS renderCartValidation(). Empty =
                     no errors = alert hidden. --}}
                <div id="cartValidationBanner" class="alert alert-danger py-2 px-3 mb-2 d-none">
                    <strong>Cannot finalize until fixed:</strong>
                    <ul id="cartValidationList" class="mb-0 small ps-3"></ul>
                </div>

                {{-- Secondary actions row (Clear/Hold/Validate) — kept
                     Laravel-only, smaller than the primary Finalize
                     button below. --}}
                <div class="d-flex flex-wrap gap-2 align-items-center px-3 py-2 border-bottom">
                    <button type="button" id="btnClear" class="btn btn-sm btn-outline-danger">
                        <i class="fas fa-trash me-1"></i> Clear
                    </button>
                    <button type="button" id="btnSoftHold" class="btn btn-sm btn-outline-warning">
                        <i class="fas fa-pause-circle me-1"></i>
                        <span id="softHoldBtnLabel">Soft Hold</span>
                    </button>
                    <button type="button" id="btnValidate" class="btn btn-sm btn-outline-info">
                        <i class="fas fa-check-double me-1"></i> Validate
                    </button>
                </div>

                {{-- Cart summary grid (image C parity — legacy sales.js
                     L859-868 layout). 2-col grid with Sub Total /
                     Transport input / Payable / Discount input, then
                     a full-width Finalize button below. --}}
                <div class="p-3 bg-light border-top">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="small text-muted mb-0">Sub Total</label>
                            <div class="fw-bold fs-5" id="cartSubtotalDisplay">0.00</div>
                        </div>
                        <div class="col-6">
                            <label class="small text-muted mb-0">Payable</label>
                            <div class="fw-bold fs-5 text-success" id="cartPayableDisplay">0.00</div>
                        </div>
                        <div class="col-6">
                            <label class="small text-muted mb-0">Transport</label>
                            <input type="number" step="0.01" min="0" id="cartTransport" class="form-control form-control-sm" value="0">
                        </div>
                        <div class="col-6">
                            <label class="small text-muted mb-0">Discount</label>
                            <input type="number" step="0.01" min="0" id="cartDiscount" class="form-control form-control-sm" value="0">
                        </div>
                        <div class="col-12">
                            <button type="button" id="btnFinalize"
                                    class="btn btn-success btn-lg w-100 btn-finalize"
                                    data-bs-toggle="tooltip" data-bs-placement="top"
                                    title="Create a draft sales invoice from this cart (GL posted)">
                                <i class="fas fa-file-invoice-dollar me-1"></i> Finalize Invoice
                            </button>
                        </div>
                    </div>
                </div>
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
@endsection

{{-- ============================================================
     R28 (2026-07-22): PWA installability meta tags.
     The cart page is the primary POS kiosk surface — making it
     installable lets a kiosk device run it as a standalone app
     (no browser chrome, larger viewport, native install prompt).
     Manifest lives at /manifest.json (served from /public).
     Service worker lives at /sw.js (registered below in @@push('scripts')).
     ============================================================ --}}
@push('head_meta')
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/svg+xml" href="/assets/images/icon.svg">
    <link rel="apple-touch-icon" href="/assets/images/icon.svg">
    <meta name="theme-color" content="#4f46e5">
    <meta name="application-name" content="RC ERP POS">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="RC POS">
    <meta name="msapplication-TileColor" content="#4f46e5">
    <meta name="msapplication-tap-highlight" content="no">
@endpush

@push('css')
{{-- ============================================================
     STYLE-PARITY Phase 1 (2026-07-22): Link legacy module CSS.
     sales-pos.css       — the full legacy sales-create stylesheet
                           (960 lines, copied from legacy/public/assets/css/).
                           Defines .sales-create-app, .sales-panel,
                           .sales-customer-due, .sales-stock-banner,
                           .sales-price-band*, .sales-entry-toolbar*,
                           .sales-cart-dock*, .sales-pos-sticky-bar, etc.
     sales-receive-payment.css — receive-payment modal styling (the cart
                                 triggers this modal from the finalize flow).
     Load order: legacy module CSS first → Laravel inline <style> second
     so Laravel-only R15/R16/R17 enhancements (which are supersets of
     the legacy rules they touch) win the cascade without !important.
     See docs/STYLE_PARITY_AUDIT_PHASE0.md §6 for the conflict table.
     ============================================================ --}}
<link rel="stylesheet" href="/assets/css/sales-pos.css">
<link rel="stylesheet" href="/assets/css/sales-receive-payment.css">

<style>
    /* ============================================================
       STYLE-PARITY Phase 3 (Decision A2): Legacy typeahead styles.
       The customer/product search boxes are now plain <input> elements
       (not Select2). The .sales-suggest-list dropdown renders clickable
       .sales-suggest-item rows (defined in sales-pos.css L85-149) with
       bold product name, code, price range, and an availability badge.
       This mirrors Legacy sales/create.php + sales-create.js exactly.
       ============================================================ */

    /* The .sales-suggest-list needs to overlay subsequent panels when
       open — bump z-index above the .sales-panel stacking context. */
    .sales-suggest-list.show {
        z-index: 1080;
    }

    /* Suggest-item right side: price range + availability badge layout.
       Mirrors Legacy sales-create.js L297-312 rendering. */
    .sales-suggest-item .suggest-meta-line {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 0.15rem;
    }
    .sales-suggest-item .suggest-meta {
        flex: 1;
        min-width: 0;
    }

    /* When the customer picker is locked (customer selected), the input
       shows the customer name in indigo on a soft indigo background —
       a clear visual cue that the customer is set and the cashier can
       now add products. Mirrors sales-pos.css L181-186 (#customerSearch.is-locked). */
    #customerSearch.is-locked {
        background: #eef2ff;
        border-color: #c7d2fe;
        font-weight: 600;
        color: var(--sales-primary-dark, #4338ca);
        cursor: default;
    }

    /* ============================================================
       STYLE-PARITY Phase 2: Minor layout fixes for the restructured cart.
       The cart-dock wrapper holds the cart table + actions + 3-col row.
       Add a little breathing room between sections.
       ============================================================ */
    #cartDock > .sales-panel + .sales-panel,
    #cartDock > .row {
        margin-top: 0.75rem;
    }

    /* The .sales-customer-due dark slate box was originally a Bootstrap
       card with text-success / text-warning / text-danger utility classes
       applied to #cdDueLeft and #cdProjectedBalance. Those Bootstrap
       utilities set color directly, which works fine on the dark bg.
       Make sure the projected-balance "highlight" row stands out. */
    .sales-customer-due .due-row.highlight strong {
        color: #fbbf24;  /* legacy amber — matches sales-pos.css L205-207 */
    }
    .sales-customer-due .due-row strong.text-success { color: #22c55e !important; }
    .sales-customer-due .due-row strong.text-warning { color: #fbbf24 !important; }
    .sales-customer-due .due-row strong.text-danger  { color: #ef4444 !important; }

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

    /* Dispatch-branch: Warehouse & Pipeline modal improvements */
    .sales-warehouse-modal {
        font-size: 0.9rem;
    }
    .sales-warehouse-modal .table th {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .sales-warehouse-modal .table td {
        vertical-align: middle;
    }
    .sales-warehouse-modal select.form-select {
        border-radius: 0.375rem;
    }
    /* Dispatch branch hint badge in stock banner */
    #dispatchBranchHint {
        animation: none;
        transition: background-color 0.2s;
    }
</style>
@endpush

@push('scripts')
<script>
(function () {
    'use strict';

    // -------- Bootstrap data from server --------
    var BRANCH_ID   = parseInt(document.getElementById('salesCartApp').dataset.branchId || '0', 10);
    var SESSION_BRANCH_ID = BRANCH_ID; // immutable: the user's actual session branch
    var INITIAL_CID = document.getElementById('salesCartApp').dataset.customerId;
    var INITIAL_CART = @json($initialCart);
    var CSRF_TOKEN  = window.CSRF_TOKEN;

    // S6: expose the current user's role + the below-min-approval
    // endpoint to the cart JS. The modal uses IS_ADMIN to decide
    // whether to show the "Override" button; the endpoint URL is
    // posted to with the approver's credentials + reason.
    window.IS_ADMIN_OR_MANAGER = {{ auth()->user() && (auth()->user()->isAdmin() || auth()->user()->hasRole('manager')) ? 'true' : 'false' }};
    window.CURRENT_USER_ID = {{ auth()->id() ?? 'null' }};
    window.BELOW_MIN_APPROVAL_ENDPOINT = "{{ route('admin.sales.below-min-approvals.store') }}";

    // Dispatch-branch: active branch name for the stock banner.
    // Updated whenever the #branch_id dropdown changes.
    window.ACTIVE_BRANCH_NAME = '{{ $branchName }}';

    // All branches data (for the warehouse modal branch filter).
    var ALL_BRANCHES = @json($allBranches->map(fn($b) => ['id' => $b->id, 'branch_name' => $b->branch_name, 'branch_code' => $b->branch_code])->values());

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
        // STYLE-PARITY Phase 3 (Decision A2): currently-selected product
        // ID for the typeahead. Set by selectProduct(), read by addToCart().
        // Kept in sync with the hidden #addProduct input — addToCart()
        // prefers the input value and falls back to this for safety.
        activeProductId: null,
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
    // ============== S6: BELOW-MIN ADMIN OVERRIDE ================
    // ============================================================
    //
    // When the cashier enters a rate below the product's min, the
    // cart UI calls promptBelowMinApproval(). This shows a SweetAlert2
    // modal asking for:
    //   - approver username
    //   - approver password (re-authentication — type=password)
    //   - reason (textarea, min 10 chars)
    //
    // On submit, the modal POSTs to /admin/sales/below-min-approvals.
    // The server re-authenticates the approver, checks their role is
    // admin/manager AT THIS MOMENT (closes the privilege-escalation
    // race where a manager's role is revoked between request and
    // approval), validates the reason length, and inserts a
    // user_audit_log row with action='below_min_override'. The
    // returned audit_log_id is passed to /cart/add as
    // `below_min_override_id`.
    //
    // Returns a Promise that resolves to { audit_log_id: int } on
    // success, or rejects on cancel/failure.
    function promptBelowMinApproval(product, rate, minRate, maxRate, defaultRate) {
        return new Promise(function (resolve, reject) {
            var html =
                '<div class="text-start">' +
                '<div class="alert alert-warning py-2 px-3 mb-3 small">' +
                '<i class="fas fa-triangle-exclamation me-1"></i>' +
                'Rate <strong>Tk ' + fmtMoney(rate) + '</strong> is below the minimum ' +
                '<strong>Tk ' + fmtMoney(minRate) + '</strong> for <strong>' +
                escHtml(product.product_name || ('Product #' + product.id)) + '</strong>. ' +
                'Admin or manager approval is required.' +
                '</div>' +

                '<div class="row g-2 mb-2">' +
                '<div class="col-6"><label class="form-label small fw-semibold">Approver Username</label>' +
                '<input type="text" id="bmApproverUser" class="form-control form-control-sm" autocomplete="off"></div>' +
                '<div class="col-6"><label class="form-label small fw-semibold">Approver Password</label>' +
                '<input type="password" id="bmApproverPass" class="form-control form-control-sm" autocomplete="new-password"></div>' +
                '</div>' +

                '<div class="mb-1"><label class="form-label small fw-semibold">Reason (min 10 chars)</label>' +
                '<textarea id="bmReason" class="form-control form-control-sm" rows="3" maxlength="500" ' +
                'placeholder="e.g. Customer is a bulk buyer, special discount approved by phone"></textarea></div>' +
                '<div class="text-muted small">The reason, approver, and rate are written to the audit log.</div>' +
                '</div>';

            Swal.fire({
                title: '<i class="fas fa-shield-halved me-2 text-warning"></i>Below-Min Approval',
                html: html,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check me-1"></i> Approve & Add',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#d97706',
                cancelButtonColor: '#6b7280',
                width: 560,
                showLoaderOnConfirm: true,
                preConfirm: function () {
                    var approverUser = document.getElementById('bmApproverUser').value.trim();
                    var approverPass = document.getElementById('bmApproverPass').value;
                    var reason = document.getElementById('bmReason').value.trim();

                    if (!approverUser || !approverPass) {
                        Swal.showValidationMessage('Approver username and password are required.');
                        return false;
                    }
                    if (reason.length < 10) {
                        Swal.showValidationMessage('Reason must be at least 10 characters.');
                        return false;
                    }

                    return ajaxPost(window.BELOW_MIN_APPROVAL_ENDPOINT, {
                        approver_username: approverUser,
                        approver_password: approverPass,
                        product_id: product.id,
                        product_name: product.product_name || null,
                        requested_rate: rate,
                        min_rate: minRate,
                        max_rate: maxRate,
                        default_rate: defaultRate,
                        reason: reason,
                        customer_id: state.customerId,
                        branch_id: BRANCH_ID,
                        cart_id: null,
                        sale_line_index: null
                    }).then(function (resp) {
                        if (resp.status === 'success' && resp.audit_log_id) {
                            return { audit_log_id: resp.audit_log_id };
                        }
                        throw new Error(resp.message || 'Approval failed.');
                    }).catch(function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message)
                            ? xhr.responseJSON.message
                            : 'Approval request failed (HTTP ' + xhr.status + ').';
                        Swal.showValidationMessage(msg);
                        return false;
                    });
                }
            }).then(function (result) {
                if (result.isConfirmed && result.value && result.value.audit_log_id) {
                    resolve({ audit_log_id: result.value.audit_log_id });
                } else {
                    reject(new Error('Below-min approval was cancelled.'));
                }
            });
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
     * - Calls selectCustomer() which locks the picker, fetches credit,
     *   and loads the cart (mirrors Legacy sales-create.js::selectCustomer).
     * - Ensures a tab exists + activates it.
     *
     * opts.skipTabEnsure (bool) — when called from inside
     * restoreSessionCarts, the tab has already been ensured; skip
     * the duplicate call.
     */
    function switchToCustomer(customerId, opts) {
        opts = opts || {};
        customerId = parseInt(customerId, 10);
        if (!customerId) return;

        // Decision A2 (2026-07-22): directly call selectCustomer instead
        // of going through Select2's .val().trigger('change'). This
        // locks the #customerSearch input, sets #customer_id, fetches
        // the credit snapshot, and loads the cart in one shot.
        selectCustomer(customerId, { skipTabEnsure: !!opts.skipTabEnsure });

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
                                // Decision A2 (2026-07-22): unlock the customer
                                // picker + clear the hidden #customer_id instead
                                // of resetting Select2.
                                clearCustomerPicker();
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
        renderCartValidation();
        recomputePayable();
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

            // ---- Desktop <tr> row (image C parity: Sl | Product | Qty | Rate | Total | Action) ----
            var row =
                '<tr data-product-id="' + productId + '">' +
                    '<td class="text-center text-muted">' + (idx + 1) + '</td>' +
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
        var sub = state.cart ? state.cart.subtotal : 0;
        $('#cartSubtotalCell').text(fmtMoney(sub));
        // Image C parity: visible Sub Total + Payable displays below the table.
        // Payable = subtotal + transport - discount. Both transport and
        // discount default to 0; their inputs are wired below.
        var transport = parseFloat($('#cartTransport').val()) || 0;
        var discount  = parseFloat($('#cartDiscount').val()) || 0;
        var payable   = sub + transport - discount;
        $('#cartSubtotalDisplay').text(fmtMoney(sub));
        $('#cartPayableDisplay').text(fmtMoney(payable));

        // R17: (re)bind swipe-to-delete on the freshly-rendered mobile cards.
        initCartSwipeRemove();
    }

    function renderSummary() {
        var items = (state.cart && state.cart.items) ? state.cart.items : [];
        var customerName = '—';
        if (state.customerId) {
            // Decision A2 (2026-07-22): customer name now comes from the
            // customerCache (populated by the typeahead) instead of a
            // Select2 <option>. Falls back to tabLabelFor which also
            // reads the cache.
            customerName = tabLabelFor(state.customerId);
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
            // S6: distinguish below-min (needs override) from above-max (hard block).
            var errorType = e.error_type || 'out_of_range';
            var cssClass = 'text-warning mb-1';
            var msg = 'rate ' + fmtMoney(e.rate) + ' (allowed ' + fmtMoney(e.min_rate) + '–' + fmtMoney(e.max_rate) + ')';
            if (errorType === 'below_min_no_override') {
                msg = 'rate ' + fmtMoney(e.rate) + ' is below minimum ' + fmtMoney(e.min_rate) +
                      ' — admin/manager approval required to add this line.';
            } else if (errorType === 'above_max') {
                cssClass = 'text-danger mb-1';
                msg = 'rate ' + fmtMoney(e.rate) + ' exceeds maximum ' + fmtMoney(e.max_rate) + '.';
            }
            $('#rateErrorsList').append(
                '<li class="' + cssClass + '">' +
                    '<strong>' + escHtml(e.product_name) + '</strong> — ' + msg +
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
        // R1: prefer productCache (typeahead-populated) for the label;
        // fall back to a bare "#id" placeholder. (Select2 <option>
        // fallback removed in Phase 3 — #addProduct is now a hidden input.)
        var cached = productCache[payload.product_id];
        var label;
        if (cached && cached.product_name) {
            label = cached.product_name + (cached.product_code ? ' [' + cached.product_code + ']' : '');
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

    // ============================================================
    // ============== STYLE-PARITY Phase 3: TYPEAHEADS ============
    // ============================================================
    // Decision A2 (2026-07-22): replaced Select2 dropdowns with legacy-
    // faithful text inputs + .sales-suggest-list autocomplete. Mirrors
    // legacy/app/views/sales/create.php + sales-create.js (L212-418).
    //
    //   #customerSearch  → text input. Typing shows .sales-suggest-item
    //                     rows (shop_name + customer_name + mobile).
    //                     Clicking a row calls selectCustomer(id), which
    //                     locks the input (.is-locked), reveals the
    //                     "Change" button, fetches the credit snapshot,
    //                     loads the cart, and ensures a tab exists.
    //
    //   #productSearch   → text input. Typing shows .sales-suggest-item
    //                     rows (product_name + code + price range +
    //                     "N avail" green/red badge — image 1 reference).
    //                     Clicking a row calls selectProduct(p), which
    //                     fills the rate / price band / availability,
    //                     focuses #addQty.
    //
    // Barcode scanners act as HID keyboards — they "type" the code
    // rapidly and end with Enter. The input handler's 200ms debounce
    // captures the full scan; Enter triggers an exact-code fallback
    // lookup against the R1 productByCode endpoint (mirrors Legacy
    // sales-create.js L353-381).

    /**
     * Format a customer's display name for the locked #customerSearch.
     * Mirrors Legacy shortCustomerName (sales-create.js L51-55):
     *   "shop_name || customer_name || 'Customer'", truncated to 22 chars.
     */
    function shortCustomerName(c) {
        if (!c) return 'Customer';
        var name = (c.shop_name || c.customer_name || 'Customer').trim();
        return name.length > 22 ? name.slice(0, 22) + '…' : name;
    }

    /**
     * Lock the #customerSearch input to a chosen customer (or unlock it
     * when the cashier clicks "Change"). Mirrors Legacy
     * setCustomerPickerLocked (sales-create.js L62-85).
     *
     * When locked, the input becomes read-only with the indigo .is-locked
     * background, the "Change" button appears, and the recents chips hide
     * (Legacy .is-hidden class — sales-pos.css L188-190).
     */
    function setCustomerPickerLocked(locked, c) {
        var $input = $('#customerSearch');
        var $btnChange = $('#btnChangeCustomer');
        var $recents = $('#customerRecentsRow');
        var $label = $('#customerSearchLabel');
        if (!$input.length) return;

        if (locked && c) {
            $input.val(shortCustomerName(c));
            $input.prop('readOnly', true);
            $input.addClass('is-locked');
            $btnChange.removeClass('d-none');
            $recents.addClass('d-none');
            if ($label.length) $label.text('Selected customer');
        } else {
            $input.prop('readOnly', false);
            $input.removeClass('is-locked');
            $input.val('');
            $btnChange.addClass('d-none');
            $recents.removeClass('d-none');
            if ($label.length) $label.text('Search name, shop or mobile');
        }
    }

    /**
     * Unlock the customer picker and focus it — used by the "Change"
     * button and by the "+ New customer" button in the cart dock.
     * Mirrors Legacy clearCustomerPickerForNew (sales-create.js L87-91).
     */
    function clearCustomerPicker() {
        setCustomerPickerLocked(false);
        $('#customer_id').val('');
        $('#customerSuggestions').removeClass('show').empty();
        // Reset the credit panel + sticky bar (no customer = nothing to show)
        fetchCustomerDetails(null);
        updatePosStickyBar();
        var $cs = $('#customerSearch');
        if ($cs.length) $cs.focus();
    }

    /**
     * Select a customer from the typeahead suggest-list.
     * Locks the picker, sets the hidden #customer_id, fetches the credit
     * snapshot, loads the cart, ensures a tab exists, and remembers the
     * customer in the recents list. Mirrors Legacy
     * sales-create.js::selectCustomer (L261-278).
     *
     * opts.skipTabEnsure (bool) — when called from restoreSessionCarts,
     * the tab has already been ensured.
     */
    function selectCustomer(customerId, opts) {
        opts = opts || {};
        customerId = parseInt(customerId, 10);
        if (!customerId) return;
        var c = customerCache[customerId] || { id: customerId };
        state.customerId = customerId;
        // Lock the picker + set hidden field
        $('#customer_id').val(customerId);
        setCustomerPickerLocked(true, c);
        // Hide the suggest list
        $('#customerSuggestions').removeClass('show').empty();
        // Remember in recents (R15) — use the cached label if available.
        rememberCustomerRecent(customerId, tabLabelFor(customerId));
        renderCustomerRecents();
        // R11: ensure a tab exists for this customer before loadCart fires.
        if (!opts.skipTabEnsure) {
            ensureTab(customerId, { active: true, label: tabLabelFor(customerId) });
        } else {
            activateTab(customerId);
        }
        // Load the cart (will call setWorkspaceVisible(true) + renderAll).
        loadCart(customerId);
        // R14: fetch the live credit snapshot for this customer.
        fetchCustomerDetails(customerId);
        // Update URL so refresh preserves selection
        if (window.history && history.replaceState) {
            history.replaceState(null, '', window.location.pathname + '?customer_id=' + customerId);
        }
        // Auto-focus the product search so the cashier can scan immediately.
        // (Mirrors Legacy sales-create.js L277.)
        var $ps = $('#productSearch');
        if ($ps.length) setTimeout(function () { $ps.focus(); }, 100);
    }

    /**
     * Select a product from the typeahead suggest-list.
     * Fills the rate / price band / availability, focuses #addQty.
     * Mirrors Legacy sales-create.js::selectProductCreate (L390-418).
     */
    function selectProduct(p) {
        if (!p || !p.id) return;
        var stock = parseFloat(p.available_qty) || 0;
        if (stock <= 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Out of stock',
                text: 'No available stock at this branch.',
                confirmButtonColor: '#7c3aed'
            });
            return;
        }
        var min = parseFloat(p.min_rate) || 0;
        var max = parseFloat(p.max_rate) || 0;
        if (min <= 0 || max <= 0) {
            Swal.fire({
                icon: 'warning',
                title: 'No price range',
                text: 'This product has no selling range set. Ask admin to configure prices.',
                confirmButtonColor: '#7c3aed'
            });
            return;
        }
        // Cache + set hidden #addProduct for back-compat with addToCart()
        productCache[p.id] = p;
        state.activeProductId = p.id;
        $('#addProduct').val(p.id);
        // Show the product name in the search input (Legacy L409)
        $('#productSearch').val(p.product_name);
        $('#productSuggestions').removeClass('show').empty();
        // Rate: default_rate (fall back to min_rate)
        var defRate = parseFloat(p.default_rate);
        if (!(defRate > 0)) defRate = min;
        $('#addRate').val(defRate.toFixed(2));
        $('#addQty').val(1);
        // Rate hint
        var hint = 'Default ' + fmtMoney(defRate) +
                   ' · Range ' + fmtMoney(min) + '–' + fmtMoney(max);
        $('#rateHint').html('<i class="fas fa-info-circle me-1"></i>' + hint);
        // R13: prime the price-range slider band
        setActivePriceRange(p);
        updatePriceBandUi();
        // Show the stock banner with this product's branch availability
        showStockBanner(p);
        // R13: check availability breakdown (per-warehouse)
        if (p.available_qty !== undefined) {
            $('#addAvailTotal').text(fmtQty(p.available_qty) + ' (live)');
        }
        checkAvailability(p.id);
        // R18: focus + select qty so the cashier can immediately type
        // a new value or press Enter to accept the default.
        var $qty = $('#addQty');
        $qty.focus();
        if ($qty[0]) $qty[0].select();
    }

    /**
     * Render the #BranchStock teal banner with this product's branch stock.
     * Mirrors Legacy showStockInfoCreate (sales-create.js L420-454).
     */
    function showStockBanner(product) {
        var $banner = $('#BranchStock');
        if (!$banner.length || !product) return;
        var stock = parseFloat(product.available_qty) || 0;
        var branchName = window.ACTIVE_BRANCH_NAME || 'Branch';
        $banner.removeClass('d-none');
        // Image B parity: only update the two text values — keep the
        // Warehouse & pipeline button intact (do NOT rebuild .stock-banner-inner).
        $('#addAvailBranchBadge').text(branchName);
        var $val = $('#addAvailTotal');
        $val.text(fmtQty(stock));
        $val.toggleClass('text-danger', stock <= 0);
        $val.toggleClass('text-white', stock > 0);
    }

    /**
     * Image B parity: "Warehouse & pipeline" button click handler.
     * Shows a SweetAlert modal with a dispatch-branch selector +
     * per-warehouse stock + pipeline amount breakdown for the
     * currently-selected product.
     *
     * Dispatch-branch enhancement: the modal now has a branch dropdown
     * at the top so the user can check stock at ANY branch's warehouses,
     * not just the currently-selected dispatch branch.
     */
    function showWarehousePipelineModal() {
        if (!state.activeProductId) {
            Swal.fire({
                icon: 'info',
                title: 'No product selected',
                text: 'Pick a product above first.',
                confirmButtonColor: '#7c3aed'
            });
            return;
        }

        var render = function (breakdown, branchName) {
            var rows = breakdown || [];
            var totalPhys = 0, totalPipe = 0, totalAvail = 0;
            var html = '';

            // Branch selector dropdown
            html += '<div class="mb-3">';
            html += '<label class="form-label small fw-semibold"><i class="fas fa-code-branch me-1"></i>Dispatch branch</label>';
            html += '<select id="wmBranchSelect" class="form-select form-select-sm">';
            ALL_BRANCHES.forEach(function (b) {
                var selected = (parseInt(b.id) === parseInt(BRANCH_ID)) ? ' selected' : '';
                html += '<option value="' + b.id + '"' + selected + '>' + escHtml(b.branch_name) + (b.branch_code ? ' (' + escHtml(b.branch_code) + ')' : '') + '</option>';
            });
            html += '</select></div>';

            // Warehouse table
            html += '<div class="table-responsive"><table class="table table-sm table-bordered mb-0">' +
                '<thead class="table-light"><tr>' +
                    '<th>Warehouse</th>' +
                    '<th class="text-end" style="width:90px">Physical</th>' +
                    '<th class="text-end" style="width:90px">Pipeline</th>' +
                    '<th class="text-end" style="width:90px">Available</th>' +
                '</tr></thead><tbody>';
            if (rows.length === 0) {
                html += '<tr><td colspan="4" class="text-center text-muted py-3">No warehouses found for this branch.</td></tr>';
            } else {
                rows.forEach(function (w) {
                    var phys  = parseFloat(w.physical_qty || 0);
                    var pipe  = parseFloat(w.pipeline_qty || 0);
                    var avail = parseFloat(w.available_qty || 0);
                    totalPhys  += phys;
                    totalPipe  += pipe;
                    totalAvail += avail;
                    var availClass = avail > 0 ? 'text-success' : 'text-danger';
                    html += '<tr>' +
                        '<td><i class="fas fa-warehouse me-1 text-muted"></i>' + escHtml(w.warehouse_name || ('#' + w.warehouse_id)) + '</td>' +
                        '<td class="text-end">' + fmtQty(phys) + '</td>' +
                        '<td class="text-end text-warning">' + fmtQty(pipe) + '</td>' +
                        '<td class="text-end fw-semibold ' + availClass + '">' + fmtQty(avail) + '</td>' +
                    '</tr>';
                });
            }
            html += '</tbody><tfoot class="table-light"><tr>' +
                '<th>Total (' + escHtml(branchName || 'Branch') + ')</th>' +
                '<th class="text-end">' + fmtQty(totalPhys) + '</th>' +
                '<th class="text-end">' + fmtQty(totalPipe) + '</th>' +
                '<th class="text-end">' + fmtQty(totalAvail) + '</th>' +
            '</tr></tfoot></table></div>';

            Swal.update({ html: html, showConfirmButton: true, confirmButtonText: 'Close' });

            // Wire the branch selector to reload data
            setTimeout(function () {
                $('#wmBranchSelect').off('change').on('change', function () {
                    var newBranchId = parseInt($(this).val(), 10);
                    if (!newBranchId || !state.activeProductId) return;
                    Swal.update({
                        html: '<div class="text-center py-3"><i class="fas fa-spinner fa-spin me-1"></i>Loading breakdown...</div>',
                        showConfirmButton: false
                    });
                    ajaxGet(ENDPOINTS.availability, { product_id: state.activeProductId, branch_id: newBranchId })
                        .done(function (payload) {
                            var bName = '';
                            ALL_BRANCHES.forEach(function (b) { if (parseInt(b.id) === newBranchId) bName = b.branch_name; });
                            render(payload.warehouse_breakdown || [], bName);
                        })
                        .fail(function () {
                            Swal.update({ html: '<div class="text-danger py-3">Failed to load warehouse breakdown. Try again.</div>', showConfirmButton: true, confirmButtonText: 'Close' });
                        });
                });
            }, 100);
        };

        // Get the current branch name for the header
        var currentBranchName = '';
        ALL_BRANCHES.forEach(function (b) { if (parseInt(b.id) === parseInt(BRANCH_ID)) currentBranchName = b.branch_name; });

        Swal.fire({
            title: '<i class="fas fa-warehouse me-2"></i>Warehouse & Pipeline',
            html: '<div class="text-center py-3"><i class="fas fa-spinner fa-spin me-1"></i>Loading breakdown...</div>',
            showCancelButton: false,
            showConfirmButton: false,
            width: 600,
            customClass: { popup: 'sales-warehouse-modal' }
        });
        // Always fetch fresh data for the selected dispatch branch
        ajaxGet(ENDPOINTS.availability, { product_id: state.activeProductId, branch_id: BRANCH_ID })
            .done(function (payload) {
                state.availBreakdown = payload.warehouse_breakdown || [];
                render(state.availBreakdown, currentBranchName);
            })
            .fail(function () {
                Swal.update({ html: '<div class="text-danger py-3">Failed to load warehouse breakdown. Try again.</div>', showConfirmButton: true, confirmButtonText: 'Close' });
            });
    }

    /**
     * Dispatch-branch change handler.
     * When the user changes the "Dispatch to branch" dropdown:
     *   1. Updates BRANCH_ID (used by finalize, product search, etc.)
     *   2. Updates ACTIVE_BRANCH_NAME (used by stock banner)
     *   3. Updates the dispatch branch hint badge
     *   4. Refreshes stock availability for the currently-selected product
     *   5. Invalidates the product cache so search returns fresh results
     *      for the new branch
     */
    function onDispatchBranchChange() {
        var $sel = $('#branch_id');
        var newBranchId = parseInt($sel.val(), 10);
        var $opt = $sel.find('option:selected');
        var newBranchName = $opt.data('branch-name') || $opt.text() || 'Branch';

        if (!newBranchId || newBranchId === BRANCH_ID) return;

        // Update the global dispatch branch ID and name
        BRANCH_ID = newBranchId;
        window.ACTIVE_BRANCH_NAME = newBranchName;

        // Update the dispatch branch hint badge in the stock banner
        $('#dispatchBranchHintText').text(newBranchName);
        $('#addAvailBranchBadge').text(newBranchName);

        // Clear product cache so next search hits the new branch
        productCache = {};

        // Re-check availability for the currently-selected product
        if (state.activeProductId) {
            checkAvailability(state.activeProductId);
            // Also update the stock banner from the product cache
            var p = productCache[state.activeProductId];
            if (p) showStockBanner(p);
        }

        // If a search term is active, re-trigger search for the new branch
        var $search = $('#productSearch');
        var term = ($search.val() || '').trim();
        if (term.length >= 2) {
            $search.trigger('input');
        }

        // Show a brief toast confirming the dispatch branch change
        var isDifferent = (BRANCH_ID !== SESSION_BRANCH_ID);
        if (isDifferent) {
            toast('Dispatch branch changed to ' + newBranchName + '. Stock now shows that branch\'s availability.', 'info');
        }
    }


    /**
     * Reset the product entry form after a successful add-to-cart.
     * Mirrors Legacy resetProductEntry (sales-create.js L625-632).
     */
    function resetProductEntry() {
        $('#productSearch').val('');
        $('#addProduct').val('');
        state.activeProductId = null;
        $('#addQty').val(1);
        $('#addRate').val('');
        $('#rateHint').html('&nbsp;');
        $('#productSuggestions').removeClass('show').empty();
        // R13: clear the price band
        setActivePriceRange(null);
        // Hide the stock banner
        $('#BranchStock').addClass('d-none');
        renderAvailability(null);
    }

    /**
     * Wire the #customerSearch input + #customerSuggestions dropdown.
     * Mirrors Legacy initCustomerTypeahead (sales-create.js L212-257).
     */
    function initCustomerTypeahead() {
        var $input = $('#customerSearch');
        var $box = $('#customerSuggestions');
        if (!$input.length || !$box.length) return;

        var _debounce = null;
        $input.on('input', function () {
            // If the input is locked (customer already selected), ignore
            // typing — the cashier must click "Change" to unlock first.
            if ($input.prop('readOnly')) return;
            var term = ($input.val() || '').trim();
            if (_debounce) clearTimeout(_debounce);
            if (term.length < 1) {
                $box.removeClass('show').empty();
                return;
            }
            _debounce = setTimeout(function () {
                ajaxGet(ENDPOINTS.searchCustomer, { term: term })
                    .done(function (data) {
                        var list = parseSalesListResponse(data);
                        if (!list.length) {
                            $box.html('<div class="sales-suggest-empty">No customer found</div>');
                            $box.addClass('show');
                            return;
                        }
                        var html = '';
                        list.forEach(function (c) {
                            customerCache[c.id] = {
                                id: c.id,
                                customer_code: c.customer_code,
                                customer_name: c.customer_name,
                                shop_name: c.shop_name,
                                mobile: c.mobile,
                                credit_limit: c.credit_limit,
                            };
                            var title = c.shop_name || c.customer_name || ('#' + c.id);
                            var meta = (c.customer_name || '') +
                                       (c.customer_code ? ' [' + c.customer_code + ']' : '') +
                                       (c.mobile ? ' · ' + c.mobile : '');
                            html += '<button type="button" class="sales-suggest-item" data-id="' + parseInt(c.id, 10) + '">' +
                                        '<span class="suggest-title">' + escHtml(title) + '</span>' +
                                        '<span class="suggest-meta">' + escHtml(meta) + '</span>' +
                                    '</button>';
                        });
                        $box.html(html);
                        $box.addClass('show');
                    })
                    .fail(function () {
                        $box.html('<div class="sales-suggest-empty">Search failed — try again</div>');
                        $box.addClass('show');
                    });
            }, 250);
        });

        // Click an item → select that customer
        $box.on('click', '.sales-suggest-item', function () {
            var id = parseInt($(this).data('id'), 10);
            if (id) selectCustomer(id);
        });

        // Enter on input → pick the first result (Legacy L244-250)
        $input.on('keydown', function (e) {
            if (e.key !== 'Enter') return;
            if ($input.prop('readOnly')) return;
            e.preventDefault();
            var $first = $box.find('.sales-suggest-item').first();
            if ($first.length) {
                selectCustomer(parseInt($first.data('id'), 10));
            }
        });

        // Outside click → close the suggest list (Legacy L252-256)
        $(document).on('click', function (e) {
            if (!$(e.target).closest('#customerSearch').length &&
                !$(e.target).closest('#customerSuggestions').length) {
                $box.removeClass('show');
            }
        });
    }

    /**
     * Wire the #productSearch input + #productSuggestions dropdown.
     * Mirrors Legacy initProductSearchCreate (sales-create.js L280-388).
     * Suggest-item layout: product name + code on left, price range +
     * availability badge on right (image 1 reference).
     */
    function initProductSearch() {
        var $input = $('#productSearch');
        var $box = $('#productSuggestions');
        if (!$input.length || !$box.length) return;

        var _debounce = null;
        $input.on('input', function () {
            var term = ($input.val() || '').trim();
            if (_debounce) clearTimeout(_debounce);
            if (term.length < 2) {
                $box.removeClass('show').empty();
                return;
            }
            _debounce = setTimeout(function () {
                ajaxGet(ENDPOINTS.searchProduct, { term: term, branch_id: BRANCH_ID })
                    .done(function (data) {
                        var list = parseSalesListResponse(data);
                        if (!list.length) {
                            $box.html('<div class="sales-suggest-empty">No product found</div>');
                            $box.addClass('show');
                            return;
                        }
                        var html = '';
                        list.forEach(function (p) {
                            productCache[p.id] = p;
                            var stock = parseFloat(p.available_qty) || 0;
                            var out = stock <= 0;
                            var min = parseFloat(p.min_rate) || 0;
                            var max = parseFloat(p.max_rate) || 0;
                            var priceLabel = (min > 0 && max > 0)
                                ? '<span class="sales-suggest-price">+' + fmtMoney(min) + '-' + fmtMoney(max) + '</span>'
                                : '<span class="sales-suggest-price text-warning">No range</span>';
                            var badgeCls = stock > 0 ? 'bg-success' : 'bg-danger';
                            html += '<button type="button" class="sales-suggest-item' + (out ? ' disabled' : '') + '"' +
                                        ' data-id="' + parseInt(p.id, 10) + '"' +
                                        (out ? ' disabled' : '') + '>' +
                                        '<span class="suggest-title">' + escHtml(p.product_name) +
                                            ' <small class="text-muted">' + escHtml(p.product_code || '') + '</small>' +
                                        '</span>' +
                                        '<span class="d-flex align-items-center gap-1">' +
                                            priceLabel +
                                            '<span class="badge ' + badgeCls + '">' + fmtQty(stock) + ' avail</span>' +
                                        '</span>' +
                                    '</button>';
                        });
                        $box.html(html);
                        $box.addClass('show');
                    })
                    .fail(function () {
                        $box.html('<div class="sales-suggest-empty">Search failed — try again</div>');
                        $box.addClass('show');
                    });
            }, 200);
        });

        // Click an item → select that product
        $box.on('click', '.sales-suggest-item:not(.disabled)', function () {
            var id = parseInt($(this).data('id'), 10);
            if (id && productCache[id]) selectProduct(productCache[id]);
        });

        // Keyboard navigation (ArrowUp/ArrowDown/Enter)
        $input.on('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (!$box.hasClass('show')) return;
                var $items = $box.find('.sales-suggest-item:not(.disabled)');
                if (!$items.length) return;
                var $active = $box.find('.sales-suggest-item.active');
                if (!$active.length) $active = $items.first();
                var idx = $items.index($active);
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    $active.removeClass('active');
                    var $next = $items.eq((idx + 1) % $items.length);
                    $next.addClass('active');
                    $next[0].scrollIntoView({ block: 'nearest' });
                } else {
                    e.preventDefault();
                    $active.removeClass('active');
                    var $prev = $items.eq((idx - 1 + $items.length) % $items.length);
                    $prev.addClass('active');
                    $prev[0].scrollIntoView({ block: 'nearest' });
                }
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                var term = ($input.val() || '').trim();
                if (!term) return;
                // If a suggest-item is active or visible, pick it.
                if ($box.hasClass('show')) {
                    var $pick = $box.find('.sales-suggest-item.active:not(.disabled)');
                    if (!$pick.length) $pick = $box.find('.sales-suggest-item:not(.disabled)').first();
                    if ($pick.length) {
                        var pid = parseInt($pick.data('id'), 10);
                        if (pid && productCache[pid]) {
                            selectProduct(productCache[pid]);
                            return;
                        }
                    }
                }
                // Fallback: treat the typed term as a product_code and
                // do an exact-match lookup against the R1 endpoint.
                lookupProductByCodeAndSelect(term);
            }
        });

        // Outside click → close the suggest list
        $(document).on('click', function (e) {
            if (!$(e.target).closest('#productSearch').length &&
                !$(e.target).closest('#productSuggestions').length) {
                $box.removeClass('show');
            }
        });
    }

    /**
     * Normalize a sales-list AJAX response into an Array. Mirrors
     * Legacy parseSalesListResponse (sales.js L45-62) — handles
     * bare arrays, {status:'error'} envelopes, {data:[...]} envelopes,
     * and numeric-keyed objects.
     */
    function parseSalesListResponse(json) {
        if (json == null) return [];
        if (Array.isArray(json)) return json;
        if (json.status === 'error') {
            console.warn(json.message || 'Sales API error');
            return [];
        }
        if (Array.isArray(json.data)) return json.data;
        if (typeof json === 'object') {
            var numericKeys = Object.keys(json).filter(function (k) { return /^\d+$/.test(k); });
            if (numericKeys.length) {
                return numericKeys
                    .sort(function (a, b) { return Number(a) - Number(b); })
                    .map(function (k) { return json[k]; });
            }
        }
        return [];
    }

    /**
     * Fallback exact-code lookup: fires when the cashier pressed Enter
     * in #productSearch with no matching suggest-list result. Calls the
     * R1 productByCode endpoint and on success calls selectProduct(p).
     * Mirrors Legacy fetchSalesProductByExactCode (sales.js L67-82).
     */
    function lookupProductByCodeAndSelect(code) {
        if (!state.customerId) {
            toast('Select a customer first.', 'error');
            return;
        }
        var trimmed = String(code || '').trim();
        if (!trimmed) return;
        $('#rateHint').html('<i class="fas fa-spinner fa-spin me-1"></i>Looking up code "' + escHtml(trimmed) + '"…');
        ajaxGet(ENDPOINTS.productByCode, { code: trimmed, branch_id: BRANCH_ID })
            .done(function (json) {
                if (!json || json.status !== 'success' || !json.data) {
                    $('#rateHint').html('<i class="fas fa-times-circle me-1 text-danger"></i>No product with code "' + escHtml(trimmed) + '".');
                    toast('No product with code ' + trimmed, 'warning');
                    return;
                }
                var p = json.data;
                productCache[p.id] = p;
                selectProduct(p);
                toast('Ready: ' + (p.product_name || trimmed) + ' · ৳' + fmtMoney(parseFloat(p.default_rate) || 0), 'success');
            })
            .fail(function (xhr) {
                $('#rateHint').html('<i class="fas fa-exclamation-circle me-1 text-danger"></i>Lookup failed: ' + escHtml(xhr.statusText || 'network error'));
                toast('Lookup failed', 'error');
            });
    }

    function setWorkspaceVisible(customerSelected) {
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
        // Decision A2 (2026-07-22): #addProduct is now a hidden input whose
        // value is set by selectProduct(). Falls back to state.activeProductId
        // for safety (in case the hidden input was cleared by a re-render).
        var productId = parseInt($('#addProduct').val() || state.activeProductId, 10);
        var qty  = parseFloat($('#addQty').val());
        var rate = parseFloat($('#addRate').val());
        if (!productId || !(qty > 0) || !(rate >= 0)) {
            toast('Pick a product, qty > 0, rate >= 0.', 'error');
            return;
        }

        // S6: check if the rate is below min. If so, prompt the
        // cashier for admin/manager approval BEFORE hitting /cart/add.
        // The approval endpoint returns an audit_log_id which we pass
        // to /cart/add as `below_min_override_id`. Without it, the
        // server hard-blocks the below-min rate.
        //
        // We read the price range from state.activePriceRange (set by
        // selectProduct() when the user picks a product). If it's null
        // (no price range configured), we skip the check and let the
        // server decide — the server's getProductPriceRange() will
        // also return null and the line will be allowed.
        var priceRange = state.activePriceRange;
        var belowMinOverrideId = null;

        var proceedWithAdd = function (overrideId) {
            var payload = {
                customer_id: state.customerId,
                product_id:  productId,
                qty:         qty,
                rate:        rate
            };
            if (overrideId) {
                payload.below_min_override_id = overrideId;
            }
            ajaxPost(ENDPOINTS.add, payload)
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
                        // Reset add form (Decision A2: plain input, not Select2)
                        resetProductEntry();

                        // R18: refocus the product search box so the cashier
                        // can immediately scan/type the next product without
                        // reaching for the mouse. Mirrors Legacy
                        // sales-create.js::resetProductEntry L632:
                        //   document.getElementById('productSearch')?.focus();
                        setTimeout(function () {
                            var $ps = $('#productSearch');
                            if ($ps.length) { $ps.focus(); }
                        }, 50);
                    } else {
                        toast(resp.message || 'Could not add item.', 'error');
                    }
                })
                .fail(function (xhr) {
                    toast('Add failed: ' + (xhr.responseJSON?.message || xhr.statusText), 'error');
                });
        };

        if (priceRange && rate < priceRange.min_rate - 0.01) {
            // Below min — prompt for approval. The modal handles the
            // AJAX to /admin/sales/below-min-approvals and returns
            // { audit_log_id } on success. On cancel/failure, we just
            // abort the add (the line is not added to the cart).
            var productObj = {
                id: productId,
                product_name: productCache[productId]?.product_name || ('Product #' + productId)
            };
            promptBelowMinApproval(
                productObj, rate,
                priceRange.min_rate, priceRange.max_rate, priceRange.default_rate
            ).then(function (result) {
                proceedWithAdd(result.audit_log_id);
            }).catch(function () {
                // Cancelled or failed — do nothing (no toast; the modal
                // already showed the error if there was one).
            });
        } else {
            proceedWithAdd(null);
        }
    }

    function updateItem(productId, qty, rate) {
        if (!state.customerId) return;
        if (!(qty > 0)) { toast('Qty must be > 0.', 'error'); return; }

        // S6: if the rate is being changed to below-min, prompt for
        // approval first (same modal as addToCart). The returned
        // audit_log_id is passed as `below_min_override_id` to /cart/update.
        var existingItem = (state.cart && state.cart.items || []).find(function (it) {
            return parseInt(it.product_id, 10) === parseInt(productId, 10);
        });
        var existingPriceRange = existingItem ? {
            min_rate: parseFloat(existingItem.min_rate),
            max_rate: parseFloat(existingItem.max_rate),
            default_rate: parseFloat(existingItem.default_rate)
        } : null;

        var proceedWithUpdate = function (overrideId) {
            var payload = {
                customer_id: state.customerId,
                product_id:  productId,
                qty:         qty
            };
            if (rate !== null && rate !== undefined && !isNaN(rate)) payload.rate = rate;
            if (overrideId) payload.below_min_override_id = overrideId;

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
        };

        if (rate !== null && rate !== undefined && !isNaN(rate)
            && existingPriceRange && existingPriceRange.min_rate
            && rate < existingPriceRange.min_rate - 0.01) {
            // Rate is being changed to below-min — prompt for approval.
            var productObj = {
                id: productId,
                product_name: existingItem ? (existingItem.product_name || ('Product #' + productId)) : ('Product #' + productId)
            };
            promptBelowMinApproval(
                productObj, rate,
                existingPriceRange.min_rate, existingPriceRange.max_rate, existingPriceRange.default_rate
            ).then(function (result) {
                proceedWithUpdate(result.audit_log_id);
            }).catch(function () {
                // Cancelled — resync to revert the rate input.
                loadCart(state.customerId);
            });
        } else {
            proceedWithUpdate(null);
        }
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
        // Dispatch-branch enhancement: pass the current dispatch branch_id
        // so availability is checked at the correct branch.
        ajaxGet(ENDPOINTS.availability, { product_id: productId, branch_id: BRANCH_ID })
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
        // Respect the locked-state hide: when the customer picker is
        // locked (.is-locked on #customerSearch), the recents row stays
        // hidden regardless of how many chips we'd render. setCustomerPickerLocked
        // toggles d-none on this row; we must not remove d-none here.
        var isLocked = $('#customerSearch').hasClass('is-locked');
        var recents = loadCustomerRecents();
        if (!recents.length || isLocked) {
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
    // ============================================================
    // ============== IMAGE C PARITY: CART SUMMARY + VALIDATION ====
    // ============================================================
    // Recompute payable = subtotal + transport - discount. Called
    // whenever transport/discount inputs change OR the cart reloads.
    function recomputePayable() {
        var sub       = state.cart ? state.cart.subtotal : 0;
        var transport = parseFloat($('#cartTransport').val()) || 0;
        var discount  = parseFloat($('#cartDiscount').val()) || 0;
        var payable   = sub + transport - discount;
        $('#cartSubtotalDisplay').text(fmtMoney(sub));
        $('#cartPayableDisplay').text(fmtMoney(payable));
    }

    // Render the pink "Cannot finalize until fixed: ..." banner.
    // Mirrors legacy sales.js applyCartValidationUi (L156-182).
    function renderCartValidation() {
        var $banner = $('#cartValidationBanner');
        var $list   = $('#cartValidationList');
        if (!$banner.length) return;
        var v = state.validation;
        if (v && v.valid === false) {
            var parts = [];
            (v.rate_errors  || []).forEach(function (e) { parts.push(e); });
            (v.stock_errors || []).forEach(function (e) { parts.push(e); });
            if (!parts.length && v.message) parts.push(v.message);
            if (!parts.length) parts.push('Cart has validation errors.');
            $list.html(parts.map(function (p) { return '<li>' + escHtml(p) + '</li>'; }).join(''));
            $banner.removeClass('d-none');
        } else {
            $banner.addClass('d-none');
            $list.empty();
        }
    }

    $(function () {
        // ============================================================
        // ============== STYLE-PARITY Phase 3: TYPEAHEAD INIT ========
        // ============================================================
        // Decision A2 (2026-07-22): replaced Select2 dropdowns with
        // legacy text-input + .sales-suggest-list autocomplete.
        // The init functions wire the input/click/keydown/outside-click
        // handlers (see definitions above, near the ACTIONS section).
        initCustomerTypeahead();
        initProductSearch();

        // --- "Change" button: unlock the customer picker so the cashier
        //     can search for a different customer. Mirrors Legacy
        //     sales-create.js setCustomerPickerLocked(false) (L78-84).
        $('#btnChangeCustomer').on('click', function () {
            clearCustomerPicker();
        });

        // --- "+ New customer" button (in the cart dock head): same as
        //     "Change" — unlocks the picker and focuses it so the cashier
        //     can immediately type a new customer name. Mirrors Legacy
        //     #btnFocusCustomer (sales/create.php L150-152 + sales-create.js
        //     clearCustomerPickerForNew at L87-91).
        $('#btnFocusCustomer').on('click', function () {
            clearCustomerPicker();
            // Scroll to the customer panel so the picker is in view.
            document.getElementById('customerSearch')?.scrollIntoView({
                behavior: 'smooth', block: 'center'
            });
        });

        // --- Tooltips ---
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (el) { return new bootstrap.Tooltip(el); });

        // R15: chip click → switch to that customer (reuses R11 flow).
        $(document).on('click', '#customerRecents .btn[data-customer-id]', function (e) {
            e.preventDefault();
            var cid = parseInt($(this).data('customer-id'), 10);
            if (!cid) return;
            if (parseInt(state.customerId, 10) === cid) return; // already active
            switchToCustomer(cid);
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
            $('#addRate').val(def.toFixed(2));
            updatePriceBandUi();
            toast('Rate reset to default (৳' + fmtMoney(def) + ')', 'info');
        });

        $('#btnAddToCart').on('click', addToCart);

        // ============================================================
        // ============== R18: KEYBOARD SHORTCUTS =====================
        // ============================================================
        // Mirrors Legacy sales-create.js keyboard flow:
        //   1. Product search ArrowUp/ArrowDown/Enter — handled inside
        //      initProductSearch() (see above).
        //   2. After a product is picked (selectProduct), auto-focus
        //      #addQty and select its content so the cashier can
        //      immediately type a new qty without an extra Tab.
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
        // ============ BARCODE SCANNER ===============================
        // ============================================================
        // Barcode scanners act as HID keyboards: they "type" the code
        // rapidly and end with Enter. The product typeahead (#productSearch)
        // is the single product entry — there is no separate barcode input
        // any more (R10's dual-mode UI was removed because it duplicated
        // the search box and made the page feel cluttered).
        //
        // Two layers of barcode support (both inside initProductSearch):
        //   1. The AJAX search matches on product_code via ILIKE (R1),
        //      so most scans resolve as the user types: the suggest-list
        //      shows matching results, the first one is highlighted, and
        //      Enter picks it.
        //   2. FALLBACK: if the user types/scans a code that returns NO
        //      matches, Enter triggers lookupProductByCodeAndSelect()
        //      (defined above) which fires the R1 productByCode endpoint
        //      and on success calls selectProduct(p) directly.
        // (No additional wiring needed here — all handled in initProductSearch.)

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
        // Image C parity: transport/discount inputs recompute payable live.
        $('#cartTransport, #cartDiscount').on('input', recomputePayable);
        // Image B parity: "Warehouse & pipeline" button shows per-warehouse
        // breakdown in a SweetAlert modal.
        $('#btnWarehousePipeline').on('click', showWarehousePipelineModal);

        // Dispatch-branch enhancement: when the user changes the dispatch
        // branch dropdown, update BRANCH_ID, refresh stock for the
        // currently-selected product, and re-trigger product search.
        $('#branch_id').on('change', onDispatchBranchChange);
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
            // Server-rendered local date (respects APP_TIMEZONE=Asia/Dhaka).
            // Must match the server's now()->format('Y-m-d') so invoices
            // created here appear in the "Today" scope filter on the
            // sales-invoices index page. Using new Date().toISOString()
            // would return UTC date, which drifts by 1 day during
            // 18:00–24:00 local time (UTC+6).
            var today = '{{ now()->format("Y-m-d") }}';

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

                    /* BUG-51: Sales Person + Dispatchers removed from the
                     * Finalize modal — they will be chosen during invoice
                     * creation (salesman_id) and challan copy-create
                     * (dispatchers), respectively. Sending them here forced
                     * the user to make a dispatching decision before the
                     * invoice was even drafted.
                     */
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

                    // BUG-51: dispatcher AJAX loader removed — dispatchers are
                    // now chosen during challan copy-create, not at finalize.
                },
                preConfirm: function () {
                    var $popup = $(Swal.getPopup());
                    var invoiceDate = $popup.find('#finInvoiceDate').val();
                    var discount = parseFloat($popup.find('#finDiscount').val()) || 0;
                    var transport = parseFloat($popup.find('#finTransport').val()) || 0;
                    var notes = $popup.find('#finNotes').val().trim();
                    var isSoftHold = $popup.find('#finSoftHold').is(':checked');
                    var override = $popup.find('#finOverride').is(':checked');
                    var overrideReason = $popup.find('#finOverrideReason').val().trim();
                    // BUG-51: salesPerson + dispatcherIds removed — chosen at
                    // invoice-create (salesman_id) and challan copy-create
                    // (dispatchers) respectively, not at finalize.

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
                        // BUG-51: sales_person + dispatcher_ids omitted —
                        // salesman_id is set during invoice create/edit,
                        // dispatchers during challan copy-create.
                        return ajaxPost(ENDPOINTS.finalize, {
                            customer_id: customerId,
                            branch_id: BRANCH_ID,
                            invoice_date: invoiceDate,
                            discount_amount: discount,
                            transport_cost: transport,
                            notes: notes,
                            is_soft_hold: isSoftHold,
                            credit_limit_override: override,
                            override_reason: override ? overrideReason : '',
                            idempotency_token: idempotencyToken
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

{{-- ============================================================
     R28 (2026-07-22): PWA service worker registration.
     Registered only on HTTPS or localhost (Chrome requirement).
     Failure is non-fatal — the page works fine without a SW; it
     just won't show the "Install app" prompt in Chrome/Edge.
     ============================================================ --}}
<script>
(function () {
    'use strict';
    if (!('serviceWorker' in navigator)) return;
    // Only register on secure contexts (HTTPS or localhost/127.0.0.1).
    // Insecure HTTP will silently fail registration in Chrome.
    if (!window.isSecureContext) return;
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then(function (reg) {
                // Successful registration — Chrome will now show the
                // install prompt after the user engages with the page.
                // (No further action needed; the SW handles its own
                // lifecycle: install / activate / fetch.)
                if (window.console && console.debug) {
                    console.debug('[R28] SW registered for scope:', reg.scope);
                }
            })
            .catch(function (err) {
                // Non-fatal — page works without SW. Log for debugging.
                if (window.console) {
                    console.warn('[R28] SW registration failed:', err);
                }
            });
    });
})();
</script>
@endpush
