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
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}"
                                @if ((int) ($selectedCustomerId ?? 0) === (int) $customer->id) selected @endif>
                                {{ $customer->customer_name }}
                                @if (!empty($customer->customer_code)) [{{ $customer->customer_code }}] @endif
                            </option>
                        @endforeach
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
                <div class="card-header bg-white border-bottom d-flex align-items-center">
                    <i class="fas fa-plus-circle me-2 text-primary"></i>
                    <strong>Add Product</strong>
                </div>
                <div class="card-body">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-5">
                            <label for="addProduct" class="form-label small fw-semibold mb-1">Product</label>
                            <select id="addProduct" class="form-select select2" style="width:100%;">
                                <option value="">— Search product —</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}"
                                            data-code="{{ $product->product_code ?? '' }}"
                                            data-name="{{ $product->product_name }}"
                                            data-default-rate="{{ $product->sales_rate ?? 0 }}">
                                        {{ $product->product_name }}
                                        @if (!empty($product->product_code)) [{{ $product->product_code }}] @endif
                                    </option>
                                @endforeach
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
                    <span class="text-muted small">Inline edits auto-save (300ms debounce)</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
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
</div>
@endsection

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
    };

    // -------- State --------
    var state = {
        customerId: INITIAL_CID ? parseInt(INITIAL_CID, 10) : null,
        cart: INITIAL_CART,         // {cart, items, subtotal, validation} or null
        softHold: !!(INITIAL_CART && INITIAL_CART.cart && INITIAL_CART.cart.is_soft_hold),
        validation: INITIAL_CART ? INITIAL_CART.validation : null,
        availBreakdown: [],         // last availability response
        availProductId: null,
        debounceTimers: {}          // productId -> setTimeout handle
    };

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
    // ============== RENDERING ===================================
    // ============================================================

    function renderAll() {
        renderCartTable();
        renderSummary();
        renderValidation(state.validation);
        // Finalize button enabled state depends on cart + validation
        var valid   = state.validation ? !!state.validation.valid : false;
        var hasItems = state.cart && state.cart.items && state.cart.items.length > 0;
        var btnFinalize = document.getElementById('btnFinalize');
        btnFinalize.disabled = !(hasItems && valid);
    }

    function renderCartTable() {
        var $body = $('#cartItemsBody');
        $body.empty();

        var items = (state.cart && state.cart.items) ? state.cart.items : [];
        $('#itemsCountBadge').text(items.length);
        $('#cartEmptyRow').toggle(items.length === 0);

        items.forEach(function (item) {
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
                        '<input type="number" class="form-control form-control-sm cart-rate" min="0" step="0.01" value="' + rate.toFixed(2) + '">' +
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
        });

        // Subtotal cell
        $('#cartSubtotalCell').text(fmtMoney(state.cart ? state.cart.subtotal : 0));
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
        var $opt = $('#addProduct option[value="' + payload.product_id + '"]');
        var label = $opt.length ? $opt.text().trim() : ('#' + payload.product_id);

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
                    } else {
                        loadCart(state.customerId);
                    }
                    // Reset add form
                    $('#addProduct').val('').trigger('change');
                    $('#addQty').val(1);
                    $('#addRate').val('');
                    $('#rateHint').html('&nbsp;');
                    renderAvailability(null);
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
    function debouncedUpdate(productId, field, value) {
        if (state.debounceTimers[productId]) {
            clearTimeout(state.debounceTimers[productId]);
        }
        state.debounceTimers[productId] = setTimeout(function () {
            // Build payload from current row state
            var $row = $('#cartItemsBody tr[data-product-id="' + productId + '"]');
            if (!$row.length) return;
            var qty  = parseFloat($row.find('.cart-qty').val());
            var rate = parseFloat($row.find('.cart-rate').val());
            updateItem(productId, qty, rate);
        }, 300);
    }

    // ============================================================
    // ============== EVENT WIRING (DOM ready) ====================
    // ============================================================
    $(function () {
        // --- Initialize Select2 ---
        $('#customerSelect').select2({ theme: 'bootstrap-5', placeholder: '— Select a customer —', allowClear: true });
        $('#addProduct').select2({ theme: 'bootstrap-5', placeholder: '— Search product —', allowClear: true });

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
                loadCart(cid);
            } else {
                if (window.history && history.replaceState) {
                    history.replaceState(null, '', window.location.pathname);
                }
                loadCart(null);
            }
        });

        $('#btnLoadCart').on('click', function () {
            loadCart($('#customerSelect').val());
        });

        // --- Add Product interactions ---
        $('#addProduct').on('change', function () {
            var $opt = $(this).find('option:selected');
            var defaultRate = $opt.data('default-rate');
            var productId = parseInt($(this).val(), 10);

            if (!productId) {
                $('#addRate').val('');
                $('#rateHint').html('&nbsp;');
                renderAvailability(null);
                return;
            }
            // Auto-fill rate with default_rate
            if (defaultRate !== undefined && defaultRate !== '') {
                $('#addRate').val(parseFloat(defaultRate).toFixed(2));
            }
            // Rate hint — show min/max range from the cart if present, else default-rate only
            var hint = 'Default: ' + fmtMoney(defaultRate);
            $('#rateHint').html('<i class="fas fa-info-circle me-1"></i>' + hint);

            // Check availability (branch-wide)
            checkAvailability(productId);
        });

        $('#btnAddToCart').on('click', addToCart);
        // Enter-key support
        $('#addQty, #addRate').on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); addToCart(); }
        });

        // --- Cart table inline edits ---
        $(document).on('input change', '.cart-qty, .cart-rate', function () {
            var $row = $(this).closest('tr');
            var productId = parseInt($row.data('product-id'), 10);

            // Optimistic local update of total cell
            var qty  = parseFloat($row.find('.cart-qty').val()) || 0;
            var rate = parseFloat($row.find('.cart-rate').val()) || 0;
            $row.find('.cart-total').text(fmtMoney(qty * rate));

            // Debounced server update
            debouncedUpdate(productId);
        });

        $(document).on('click', '.cart-remove', function () {
            var productId = parseInt($(this).closest('tr').data('product-id'), 10);
            removeItem(productId);
        });

        // --- Cart action buttons ---
        $('#btnClear').on('click', clearCart);
        $('#btnSoftHold').on('click', toggleSoftHold);
        $('#btnValidate').on('click', validateCart);
        $('#btnFinalize').on('click', function () {
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
                            override_reason: override ? overrideReason : ''
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
    });
})();
</script>
@endpush
