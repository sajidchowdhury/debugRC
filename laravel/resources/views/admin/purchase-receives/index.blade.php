@extends('layouts.admin')

@push('css')
<link rel="stylesheet" href="/assets/css/purchase-index.css">
@endpush

@section('content')
@php
    // Laravel status enum → legacy purch-badge-* class map.
    // (Laravel uses {draft, confirmed, cancelled}; legacy CSS uses the
    // same set of badge classes we already set up in Phase 2.)
    $badgeClass = [
        'draft'     => 'purch-badge-draft',
        'confirmed' => 'purch-badge-received',
        'cancelled' => 'purch-badge-cancelled',
    ];
    $cardClass = [
        'draft'     => 'status-draft',
        'confirmed' => 'status-done',
        'cancelled' => 'status-cancel',
    ];
    $statusLabel = [
        'draft'     => 'Draft',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
    ];

    $branchName = auth()->user()?->branch?->branch_name ?? 'All branches';
    $showReturned = request()->boolean('returned');

    $stats = array_merge([
        'total' => 0, 'draft' => 0, 'confirmed' => 0,
        'cancelled' => 0, 'total_value' => 0,
    ], is_array($stats ?? null) ? $stats : []);
@endphp

<div class="purch-index-app purch-grn" id="purchase-receive-app">
    {{-- ─── Hero ──────────────────────────────────────────────── --}}
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-dolly me-2"></i>{{ $showReturned ? 'Returned / cancelled GRNs' : 'Goods received (GRN)' }}</h1>
            <p>Record stock-in from suppliers — linked to PO or direct purchase.</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i>{{ e($branchName) }}</span>
            <span class="purch-index-tag is-alt"><i class="fas fa-warehouse me-1"></i>Stock in</span>
        </div>
        <div class="purch-index-hero-actions">
            @if ($showReturned)
                <a href="{{ route('admin.purchase-receives.index') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Active GRNs
                </a>
            @else
                <a href="{{ route('admin.purchase-receives.create') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-plus me-1"></i>New receive
                </a>
                <a href="{{ route('admin.purchase-receives.index', ['returned' => 1]) }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-undo me-1"></i>Returned
                </a>
                <a href="{{ route('admin.purchase-receives.export', request()->only(['from_date','to_date','search','status'])) }}"
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

    {{-- ─── Stat cards ─────────────────────────────────────────── --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-list"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['total']) }}</div>
                        <div class="text-muted small">Total GRNs</div>
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
                         style="width:48px;height:48px;background:#15803d;">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['confirmed']) }}</div>
                        <div class="text-muted small">Confirmed</div>
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
                        <div class="text-muted small">Total value (confirmed)</div>
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
                @if (! $showReturned)
                <div class="purch-index-smart-label">Status</div>
                <div class="purch-index-status-chips mb-3">
                    <button type="button" class="purch-index-status-chip active" data-status="all">All</button>
                    <button type="button" class="purch-index-status-chip" data-status="draft">Draft</button>
                    <button type="button" class="purch-index-status-chip" data-status="confirmed">Confirmed</button>
                </div>
                <input type="hidden" id="filterStatus" value="">
                @else
                <input type="hidden" id="filterStatus" value="cancelled">
                @endif
                <div class="purch-index-smart-label">Smart search</div>
                <div class="purch-index-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search" class="form-control purch-index-search-input" id="filterSearch"
                           placeholder="GRN code, PO, supplier…" autocomplete="off">
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
        <div class="purch-index-mobile-cards" id="receiveCards"></div>
        <div class="table-responsive p-2">
            <table id="receiveTable" class="table table-hover align-middle mb-0 w-100">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>GRN #</th>
                        <th>PO #</th>
                        <th>Supplier</th>
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
window.PURCHASE_RECEIVE_BOOT = {
    indexUrl: @json(route('admin.purchase-receives.index', ['returned' => $showReturned ? 1 : null])),
    csrf:     @json(csrf_token()),
    showReturned: @json($showReturned),
    statusLabels: @json($statusLabel),
    badgeClass:   @json($badgeClass),
    cardClass:    @json($cardClass),
};
</script>
<script>
(function () {
    var boot = window.PURCHASE_RECEIVE_BOOT || {};
    var receiveTable = null;

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
        var parts = String(d).split('-');
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
        if (boot.showReturned) tags.push('<span class="filter-tag"><i class="fas fa-undo me-1"></i>Returned view</span>');
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
        if (receiveTable) receiveTable.ajax.reload(null, false);
        saveFilters();
    }
    function saveFilters() {
        try {
            localStorage.setItem('purchase_receive_filters_v1', JSON.stringify({
                from: $('#filterDateFrom').val(),
                to:   $('#filterDateTo').val(),
                status: $('#filterStatus').val(),
                search: $('#filterSearch').val(),
            }));
        } catch (e) { /* localStorage may be blocked */ }
    }
    function loadFilters() {
        try {
            var raw = localStorage.getItem('purchase_receive_filters_v1');
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
        if (row.can_confirm) {
            html += '<button type="button" class="btn btn-outline-success btn-confirm-grn" data-id="' + row.id + '" data-code="' + escapeHtml(row.receive_code) + '" data-url="' + escapeHtml(row.confirm_url) + '" title="Confirm"><i class="fas fa-check"></i></button>';
        }
        if (row.can_cancel) {
            html += '<button type="button" class="btn btn-outline-danger btn-cancel-grn" data-id="' + row.id + '" data-code="' + escapeHtml(row.receive_code) + '" data-url="' + escapeHtml(row.cancel_url) + '" title="Cancel"><i class="fas fa-ban"></i></button>';
        }
        html += '</div>';
        return html;
    }
    function renderCards(table) {
        var $container = $('#receiveCards');
        if (!$container.length || window.innerWidth >= 768) {
            if ($container.length) $container.empty();
            return;
        }
        var data = table.rows({ page: 'current' }).data();
        var html = '';
        data.each(function (row) {
            var sc = cardStatusClass(row.status);
            var poPill = row.po_code
                ? '<a href="' + escapeHtml(row.po_show_url) + '" class="badge bg-info text-white text-decoration-none">PO ' + escapeHtml(row.po_code) + '</a>'
                : '<span class="badge bg-light text-dark"><i class="fas fa-bolt me-1"></i>Direct</span>';
            var reversed = row.is_reversed ? ' <span class="badge bg-danger ms-1"><i class="fas fa-rotate-left"></i></span>' : '';
            html += '<div class="purch-index-mobile-card ' + sc + '">' +
                '<div class="d-flex justify-content-between">' +
                    '<strong>' + escapeHtml(row.receive_code) + reversed + '</strong>' +
                    '<span class="text-muted small">' + formatDate(row.receive_date) + '</span>' +
                '</div>' +
                '<div class="mt-1">' + escapeHtml(row.supplier_name) + ' ' + poPill + '</div>' +
                '<div class="small text-muted">' + escapeHtml(row.branch_name || '') + '</div>' +
                '<div class="mt-2 d-flex justify-content-between align-items-center">' +
                    statusBadge(row.status) +
                    '<span class="purch-index-amt">' + formatMoney(row.total_amount) + '</span>' +
                '</div>' +
                '<div class="mt-2">' + buildActions(row) + '</div>' +
            '</div>';
        });
        $container.html(html || '<p class="text-muted text-center py-3 mb-0">No GRNs found.</p>');
    }
    function initDataTable() {
        var ajaxUrl = boot.indexUrl;
        receiveTable = $('#receiveTable').DataTable({
            processing: true,
            serverSide: true,
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                emptyTable: 'No GRNs match your filters',
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
                    if (boot.showReturned) d.returned = 1;
                },
            },
            drawCallback: function () {
                var info = receiveTable.page.info();
                $('#resultsCountNum').text(info.recordsDisplay);
                renderCards(receiveTable);
                updateActiveBar();
            },
            columns: [
                { data: 'receive_date', render: formatDate },
                {
                    data: 'receive_code',
                    render: function (data, type, row) {
                        var reversed = row.is_reversed ? ' <span class="badge bg-danger ms-1"><i class="fas fa-rotate-left"></i></span>' : '';
                        return '<a href="' + escapeHtml(row.show_url) + '" class="fw-bold text-decoration-none">' + escapeHtml(data) + '</a>' + reversed;
                    },
                },
                {
                    data: 'po_code',
                    render: function (data, type, row) {
                        if (!data) return '<span class="badge bg-light text-dark"><i class="fas fa-bolt me-1"></i>Direct</span>';
                        return row.po_show_url
                            ? '<a href="' + escapeHtml(row.po_show_url) + '" class="text-decoration-none">' + escapeHtml(data) + '</a>'
                            : escapeHtml(data);
                    },
                },
                { data: 'supplier_name', render: escapeHtml },
                { data: 'total_amount', className: 'text-end', render: formatMoney },
                { data: 'status', render: statusBadge },
                { data: 'created_by_name', defaultContent: '—', render: escapeHtml },
                {
                    data: 'id', orderable: false, className: 'text-center',
                    render: function (id, type, row) { return buildActions(row); },
                },
            ],
        });
        window.receiveTable = receiveTable;
    }

    // ── Confirm GRN (SweetAlert2 + form POST) ─────────────────────
    function bindConfirmGrn() {
        $(document).off('click', '.btn-confirm-grn').on('click', '.btn-confirm-grn', function (e) {
            e.preventDefault();
            var url  = $(this).data('url');
            var code = $(this).data('code') || 'GRN';
            Swal.fire({
                title: 'Confirm GRN?',
                html: 'Confirm <strong>' + escapeHtml(code) + '</strong>? This will:<ul class="text-start small mb-0">' +
                      '<li>Move stock INTO inventory (avg cost recalculated)</li>' +
                      '<li>Post GL: <em>Dr Inventory / Cr Accounts Payable</em></li>' +
                      '<li>Update supplier ledger (credit)</li>' +
                      '<li>Update PO received quantities</li></ul>',
                showCancelButton: true,
                confirmButtonText: 'Confirm GRN',
                confirmButtonColor: '#16a34a',
                returnFocus: false,
            }).then(function (result) {
                if (!result.isConfirmed) return;
                var $f = $('<form method="POST" action="' + url + '"></form>');
                $f.append('<input type="hidden" name="_token" value="' + boot.csrf + '">');
                $f.append('<input type="hidden" name="confirm_reason" value="">');
                $('body').append($f);
                $f.submit();
            });
        });
    }

    // ── Cancel GRN (SweetAlert2 + form POST) ──────────────────────
    function bindCancelGrn() {
        $(document).off('click', '.btn-cancel-grn').on('click', '.btn-cancel-grn', function (e) {
            e.preventDefault();
            var url  = $(this).data('url');
            var code = $(this).data('code') || 'GRN';
            Swal.fire({
                title: 'Cancel GRN?',
                html: 'Cancel <strong>' + escapeHtml(code) + '</strong>? This will reverse all stock + GL + ledger postings and cannot be undone easily.',
                input: 'textarea',
                inputPlaceholder: 'Reason (min 5 characters)',
                inputAttributes: { maxlength: 500 },
                showCancelButton: true,
                confirmButtonText: 'Cancel GRN',
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
        $(window).on('resize', function () { if (receiveTable) renderCards(receiveTable); });
    }

    // ── Boot ──────────────────────────────────────────────────────
    $(function () {
        if (!$('#purchase-receive-app').length) return;
        // For "Returned" view, force status filter to cancelled.
        if (boot.showReturned) {
            $('#filterStatus').val('cancelled');
        }
        var loaded = loadFilters();
        if (!loaded && !boot.showReturned) {
            applyDatePreset('month', false);
        } else {
            $('.purch-index-preset-btn').removeClass('active');
        }
        if (boot.showReturned) {
            $('#filterStatus').val('cancelled');
        }
        syncStatusChips();
        bindUi();
        bindConfirmGrn();
        bindCancelGrn();
        initDataTable();
        updateActiveBar();
    });
})();
</script>
@endpush
@endsection
