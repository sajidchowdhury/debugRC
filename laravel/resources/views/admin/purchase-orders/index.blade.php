@extends('layouts.admin')

@push('css')
<link rel="stylesheet" href="/assets/css/purchase-index.css">
@endpush

@section('content')
@php
    // Laravel status enum → legacy purch-badge-* class map.
    // (Laravel uses {draft, sent, partial, received, cancelled};
    // legacy CSS expects {draft, pending, partial, received/completed, cancelled, reversed}.)
    $badgeClass = [
        'draft'     => 'purch-badge-draft',
        'sent'      => 'purch-badge-pending',
        'partial'   => 'purch-badge-partial',
        'received'  => 'purch-badge-received',
        'cancelled' => 'purch-badge-cancelled',
    ];
    $cardClass = [
        'draft'     => 'status-draft',
        'sent'      => 'status-pending',
        'partial'   => 'status-partial',
        'received'  => 'status-done',
        'cancelled' => 'status-cancel',
    ];
    $statusLabel = [
        'draft'     => 'Draft',
        'sent'      => 'Sent',
        'partial'   => 'Partial',
        'received'  => 'Received',
        'cancelled' => 'Cancelled',
    ];

    $branchName = auth()->user()?->branch?->branch_name ?? 'All branches';
    $showCancelled = request()->boolean('cancelled');

    $stats = array_merge([
        'total' => 0, 'draft' => 0, 'sent' => 0, 'partial' => 0,
        'received' => 0, 'cancelled' => 0, 'total_value' => 0,
    ], is_array($stats ?? null) ? $stats : []);
@endphp

<div class="purch-index-app" id="purchase-order-app">
    {{-- ─── Hero ──────────────────────────────────────────────── --}}
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-file-invoice me-2"></i>{{ $showCancelled ? 'Cancelled purchase orders' : 'Purchase orders' }}</h1>
            <p>Plan supplier buys, track receipt progress, and manage PO lifecycle.</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i>{{ e($branchName) }}</span>
            <span class="purch-index-tag is-alt"><i class="fas fa-truck-loading me-1"></i>Procurement</span>
        </div>
        <div class="purch-index-hero-actions">
            @if ($showCancelled)
                <a href="{{ route('admin.purchase-orders.index') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Active POs
                </a>
            @else
                <a href="{{ route('admin.purchase-orders.create') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-plus me-1"></i>New PO
                </a>
                <a href="{{ route('admin.purchase-orders.index', ['cancelled' => 1]) }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-ban me-1"></i>Cancelled
                </a>
                <a href="{{ route('admin.purchase-orders.export', request()->only(['from_date','to_date','search','status'])) }}"
                   class="btn btn-outline-light btn-sm" title="CSV export">
                    <i class="fas fa-file-csv me-1"></i>Export
                </a>
            @endif
            <button type="button" class="btn btn-outline-light btn-sm collapsed" id="togglePurchFilters"
                    data-bs-toggle="collapse" data-bs-target="#purchFiltersCollapse" aria-expanded="false" title="Filters">
                <i class="fas fa-filter me-1"></i>Filters
            </button>
        </div>
    </header>

    {{-- ─── Stat cards (Laravel-only addition — keeps the rich overview the user already had) ─── --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#2563eb;">
                        <i class="fas fa-list"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['total']) }}</div>
                        <div class="text-muted small">Total</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#d97706;">
                        <i class="fas fa-pen-to-square"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['draft']) }}</div>
                        <div class="text-muted small">Draft</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0ea5e9;">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['sent']) }}</div>
                        <div class="text-muted small">Sent</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#4f46e5;">
                        <i class="fas fa-circle-half-stroke"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['partial']) }}</div>
                        <div class="text-muted small">Partial</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['received']) }}</div>
                        <div class="text-muted small">Received</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#64748b;">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['cancelled']) }}</div>
                        <div class="text-muted small">Cancelled</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#7c3aed;">
                        <i class="fas fa-taka-sign"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['total_value'], 2) }}</div>
                        <div class="text-muted small">Total value (ex. cancelled)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ─── Filter collapse panel (legacy structure) ─────────────── --}}
    <div class="purch-index-filters-shell">
        <div class="collapse" id="purchFiltersCollapse">
            <div class="purch-index-smart-panel">
                <div class="purch-index-smart-label">Quick date range</div>
                <div class="purch-index-preset-row">
                    <button type="button" class="purch-index-preset-btn" data-preset="today">Today</button>
                    <button type="button" class="purch-index-preset-btn" data-preset="yesterday">Yesterday</button>
                    <button type="button" class="purch-index-preset-btn" data-preset="week">Last 7 days</button>
                    <button type="button" class="purch-index-preset-btn active" data-preset="month">This month</button>
                    <button type="button" class="purch-index-preset-btn" data-preset="custom">Custom</button>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">From</label>
                        <input type="date" class="form-control form-control-sm" id="filterDateFrom">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">To</label>
                        <input type="date" class="form-control form-control-sm" id="filterDateTo">
                    </div>
                </div>
                <div class="purch-index-smart-label">Status</div>
                <div class="purch-index-status-chips mb-3">
                    <button type="button" class="purch-index-status-chip active" data-status="all">All</button>
                    <button type="button" class="purch-index-status-chip" data-status="draft">Draft</button>
                    <button type="button" class="purch-index-status-chip" data-status="sent">Sent</button>
                    <button type="button" class="purch-index-status-chip" data-status="partial">Partial</button>
                    <button type="button" class="purch-index-status-chip" data-status="received">Received</button>
                    @if ($showCancelled)
                        <button type="button" class="purch-index-status-chip chip-warn active" data-status="cancelled">Cancelled</button>
                    @endif
                </div>
                <input type="hidden" id="filterStatus" value="{{ $showCancelled ? 'cancelled' : '' }}">
                <div class="purch-index-smart-label">Smart search</div>
                <div class="purch-index-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search" class="form-control purch-index-search-input" id="filterSearch"
                           placeholder="PO code, supplier, branch…" autocomplete="off">
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="clearFilters">
                    <i class="fas fa-eraser me-1"></i>Clear filters
                </button>
            </div>
        </div>
    </div>

    {{-- ─── Active filter bar (renders filter tags) ──────────────── --}}
    <div class="purch-index-active-bar" id="activeFilterBar"></div>

    {{-- ─── Results card (table + mobile cards) ──────────────────── --}}
    <div class="purch-index-results-card">
        <div class="purch-index-results-head">
            <span class="fw-semibold"><i class="fas fa-list me-1"></i> Results</span>
            <span class="text-muted small"><span id="resultsCountNum">0</span> record(s)</span>
        </div>
        <div class="purch-index-mobile-cards" id="poCards"></div>
        <div class="table-responsive p-2">
            <table id="poTable" class="table table-hover align-middle mb-0 w-100">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>PO #</th>
                        <th>Supplier</th>
                        <th>Branch</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Created by</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

@push('scripts')
<script>
window.PURCHASE_ORDER_BOOT = {
    baseUrl: '/',
    indexUrl: @json(route('admin.purchase-orders.index', ['cancelled' => $showCancelled ? 1 : null])),
    csrf:     @json(csrf_token()),
    showCancelled: @json($showCancelled),
    statusLabels: @json($statusLabel),
    badgeClass:   @json($badgeClass),
    cardClass:    @json($cardClass),
};
</script>
<script>
(function () {
    var boot = window.PURCHASE_ORDER_BOOT || {};
    var poTable = null;

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function formatMoney(n) {
        var v = parseFloat(n) || 0;
        return 'Tk ' + v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function formatDate(d) {
        if (!d) return '—';
        var parts = String(d).split('-'); // YYYY-MM-DD
        if (parts.length !== 3) return d;
        return parts[2] + '-' + parts[1] + '-' + parts[0];
    }
    function statusBadge(status) {
        var cls = (boot.badgeClass || {})[status] || 'purch-badge-draft';
        var lbl = (boot.statusLabels || {})[status] || status;
        return '<span class="purch-badge ' + cls + '">' + escapeHtml(lbl) + '</span>';
    }
    function cardStatusClass(status) {
        return (boot.cardClass || {})[status] || 'status-draft';
    }

    // ── Filter UI ─────────────────────────────────────────────────
    function syncStatusChips() {
        var v = $('#filterStatus').val() || 'all';
        $('.purch-index-status-chip').removeClass('active');
        $('.purch-index-status-chip[data-status="' + v + '"]').addClass('active');
    }
    function dateRangeForPreset(preset) {
        var today = new Date();
        var fmt = function (d) {
            var m = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            return d.getFullYear() + '-' + m + '-' + day;
        };
        var from = '', to = fmt(today);
        if (preset === 'today') { from = fmt(today); }
        else if (preset === 'yesterday') {
            var y = new Date(today); y.setDate(y.getDate() - 1);
            from = fmt(y); to = fmt(y);
        } else if (preset === 'week') {
            var w = new Date(today); w.setDate(w.getDate() - 6);
            from = fmt(w);
        } else if (preset === 'month') {
            from = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-01';
        }
        return { from: from, to: to };
    }
    function applyDatePreset(preset, reload) {
        var r = dateRangeForPreset(preset);
        $('#filterDateFrom').val(r.from);
        $('#filterDateTo').val(r.to);
        $('.purch-index-preset-btn').removeClass('active');
        $('.purch-index-preset-btn[data-preset="' + preset + '"]').addClass('active');
        if (reload !== false) reloadTable();
    }
    function updateActiveBar() {
        var tags = [];
        var from = $('#filterDateFrom').val();
        var to   = $('#filterDateTo').val();
        if (from) tags.push('<span class="filter-tag"><i class="fas fa-calendar me-1"></i>From ' + escapeHtml(from) + '</span>');
        if (to)   tags.push('<span class="filter-tag"><i class="fas fa-calendar me-1"></i>To '   + escapeHtml(to)   + '</span>');
        var st = $('#filterStatus').val();
        if (st && st !== 'all') tags.push('<span class="filter-tag"><i class="fas fa-flag me-1"></i>' + escapeHtml((boot.statusLabels||{})[st] || st) + '</span>');
        var s = $('#filterSearch').val();
        if (s) tags.push('<span class="filter-tag"><i class="fas fa-search me-1"></i>"' + escapeHtml(s) + '"</span>');
        if (boot.showCancelled) tags.push('<span class="filter-tag"><i class="fas fa-ban me-1"></i>Cancelled view</span>');
        $('#activeFilterBar').html(tags.join(''));
    }
    function resetFilters() {
        $('#filterStatus').val('');
        $('#filterSearch').val('');
        applyDatePreset('month', false);
        syncStatusChips();
        reloadTable();
    }
    function reloadTable() {
        if (poTable) poTable.ajax.reload(null, false);
        saveFilters();
    }
    function saveFilters() {
        try {
            localStorage.setItem('purchase_order_filters_v1', JSON.stringify({
                from: $('#filterDateFrom').val(),
                to:   $('#filterDateTo').val(),
                status: $('#filterStatus').val(),
                search: $('#filterSearch').val(),
            }));
        } catch (e) { /* localStorage may be blocked */ }
    }
    function loadFilters() {
        try {
            var raw = localStorage.getItem('purchase_order_filters_v1');
            if (!raw) return false;
            var f = JSON.parse(raw);
            if (f.from)   $('#filterDateFrom').val(f.from);
            if (f.to)     $('#filterDateTo').val(f.to);
            if (f.status) $('#filterStatus').val(f.status);
            if (f.search) $('#filterSearch').val(f.search);
            syncStatusChips();
            return true;
        } catch (e) { return false; }
    }
    function syncFiltersToggleBtn() {
        var $btn = $('#togglePurchFilters');
        var open = $('#purchFiltersCollapse').hasClass('show');
        $btn.toggleClass('collapsed', !open);
    }

    // ── DataTables ────────────────────────────────────────────────
    function buildActions(row) {
        var html = '<div class="btn-group btn-group-sm">';
        html += '<a href="' + escapeHtml(row.show_url) + '" class="btn btn-outline-secondary" title="View"><i class="fas fa-eye"></i></a>';
        if (row.can_edit) {
            html += '<a href="' + escapeHtml(row.edit_url) + '" class="btn btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>';
        }
        if (row.can_cancel) {
            html += '<button type="button" class="btn btn-outline-danger btn-cancel-po" data-id="' + row.id + '" data-code="' + escapeHtml(row.po_code) + '" data-url="' + escapeHtml(row.cancel_url) + '" title="Cancel"><i class="fas fa-ban"></i></button>';
        }
        html += '</div>';
        return html;
    }
    function renderCards(table) {
        var $container = $('#poCards');
        if (!$container.length || window.innerWidth >= 768) {
            if ($container.length) $container.empty();
            return;
        }
        var data = table.rows({ page: 'current' }).data();
        var html = '';
        data.each(function (row) {
            var sc = cardStatusClass(row.status);
            html += '<div class="purch-index-mobile-card ' + sc + '">' +
                '<div class="d-flex justify-content-between">' +
                    '<strong>' + escapeHtml(row.po_code) + '</strong>' +
                    '<span class="text-muted small">' + formatDate(row.po_date) + '</span>' +
                '</div>' +
                '<div class="mt-1">' + escapeHtml(row.supplier_name) + '</div>' +
                '<div class="small text-muted">' + escapeHtml(row.branch_name || '') + '</div>' +
                '<div class="mt-2 d-flex justify-content-between align-items-center">' +
                    statusBadge(row.status) +
                    '<span class="purch-index-amt">' + formatMoney(row.total_amount) + '</span>' +
                '</div>' +
                '<div class="mt-2">' + buildActions(row) + '</div>' +
            '</div>';
        });
        $container.html(html || '<p class="text-muted text-center py-3 mb-0">No orders found.</p>');
    }
    function initDataTable() {
        var ajaxUrl = boot.indexUrl;
        poTable = $('#poTable').DataTable({
            processing: true,
            serverSide: true,
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                emptyTable: 'No purchase orders match your filters',
                processing: '<i class="fas fa-spinner fa-spin"></i> Loading…',
            },
            ajax: {
                url: ajaxUrl,
                data: function (d) {
                    d.datatables = 1;
                    d.date_from    = $('#filterDateFrom').val();
                    d.date_to      = $('#filterDateTo').val();
                    d.filterStatus = $('#filterStatus').val();
                    d.search       = $('#filterSearch').val();
                    if (boot.showCancelled) d.cancelled = 1;
                },
            },
            drawCallback: function () {
                var info = poTable.page.info();
                $('#resultsCountNum').text(info.recordsDisplay);
                renderCards(poTable);
                updateActiveBar();
            },
            columns: [
                { data: 'po_date', render: formatDate },
                {
                    data: 'po_code',
                    render: function (data, type, row) {
                        return '<a href="' + escapeHtml(row.show_url) + '" class="fw-bold text-decoration-none">' + escapeHtml(data) + '</a>';
                    },
                },
                { data: 'supplier_name', render: escapeHtml },
                { data: 'branch_name', render: escapeHtml },
                { data: 'total_amount', className: 'text-end', render: formatMoney },
                { data: 'status', render: statusBadge },
                { data: 'created_by_name', defaultContent: '—', render: escapeHtml },
                {
                    data: 'id', orderable: false, className: 'text-center',
                    render: function (id, type, row) { return buildActions(row); },
                },
            ],
        });
        window.poTable = poTable;
    }

    // ── Cancel PO (SweetAlert2 + form POST) ───────────────────────
    function bindCancelPo() {
        $(document).off('click', '.btn-cancel-po').on('click', '.btn-cancel-po', function (e) {
            e.preventDefault();
            var id   = $(this).data('id');
            var code = $(this).data('code') || ('PO-' + id);
            var url  = $(this).data('url');
            Swal.fire({
                title: 'Cancel purchase order?',
                html: 'Cancel <strong>' + escapeHtml(code) + '</strong>? This cannot be undone easily.',
                input: 'textarea',
                inputPlaceholder: 'Reason (min 5 characters)',
                inputAttributes: { maxlength: 500 },
                showCancelButton: true,
                confirmButtonText: 'Cancel PO',
                confirmButtonColor: '#dc2626',
                returnFocus: false,
                preConfirm: function (v) {
                    var r = String(v || '').trim();
                    if (r.length < 5) {
                        Swal.showValidationMessage('Please provide a reason (minimum 5 characters).');
                        return false;
                    }
                    return r;
                },
            }).then(function (result) {
                if (!result.isConfirmed || !result.value) return;
                // Build a hidden form and POST it (so Laravel's CSRF + redirect work).
                var $f = $('<form method="POST" action="' + url + '"></form>');
                $f.append('<input type="hidden" name="_token" value="' + boot.csrf + '">');
                $f.append('<input type="hidden" name="cancel_reason" value="' + escapeHtml(result.value) + '">');
                $('body').append($f);
                $f.submit();
            });
        });
    }

    // ── Filter UI bindings ────────────────────────────────────────
    function bindUi() {
        $('.purch-index-preset-btn').on('click', function () {
            applyDatePreset($(this).data('preset'));
        });
        $('.purch-index-status-chip').on('click', function () {
            var st = $(this).data('status');
            $('#filterStatus').val(st === 'all' ? '' : st);
            syncStatusChips();
            reloadTable();
        });
        $('#filterDateFrom, #filterDateTo').on('change', function () {
            $('.purch-index-preset-btn').removeClass('active');
            reloadTable();
        });
        var _searchDebounce = null;
        $('#filterSearch').on('input', function () {
            if (_searchDebounce) clearTimeout(_searchDebounce);
            _searchDebounce = setTimeout(reloadTable, 300);
        });
        $('#clearFilters').on('click', resetFilters);
        $('#purchFiltersCollapse').on('shown.bs.collapse hidden.bs.collapse', syncFiltersToggleBtn);
        $(window).on('resize', function () { if (poTable) renderCards(poTable); });
    }

    // ── Boot ──────────────────────────────────────────────────────
    $(function () {
        if (!$('#purchase-order-app').length) return;
        // Default = "month" preset (unless persisted filters override).
        var loaded = loadFilters();
        if (!loaded) {
            applyDatePreset('month', false);
        } else {
            // If persisted filters exist, don't force 'month'.
            $('.purch-index-preset-btn').removeClass('active');
        }
        syncStatusChips();
        bindUi();
        bindCancelPo();
        initDataTable();
        updateActiveBar();
    });
})();
</script>
@endpush
@endsection
