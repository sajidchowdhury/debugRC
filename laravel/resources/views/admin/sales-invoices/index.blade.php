<x-layouts.erp :title="$title ?? 'Sales Invoices'" :tabs="[
    ['label' => 'Dashboard', 'href' => route('dashboard')],
    ['label' => 'Invoices', 'href' => route('admin.sales-invoices.index'), 'active' => true],
    ['label' => 'Challans', 'href' => route('admin.sales-challans.index')],
    ['label' => 'UI Preview', 'href' => route('ui-preview')],
]">
@php
    // Defaults for filter controls (initial page render only —
    // subsequent filter changes are dispatched via DataTables AJAX
    // + the R22 summary endpoint, so the page itself never reloads).
    $filters = array_merge([
        'from_date'   => '',
        'to_date'     => '',
        'customer_id' => '',
        'branch_id'   => '',
        'status'      => '',
        'status_chip' => 'all',
        'search'      => '',
        'smart_sort'  => '1',
    ], is_array($filters ?? null) ? $filters : []);

    // BUG-52: scope chip — 'today', 'pending_godown', 'pending_challan'.
    // Defaults to 'today' on first visit so the menu label "Today Invoice"
    // finally matches reality. User can click "All" to clear.
    $scope = $scope ?? ($filters['scope'] ?? null);
    if (!in_array($scope, ['today', 'pending_godown', 'pending_challan', 'all', null], true)) {
        $scope = null;
    }
    // First-visit default: if no scope and no explicit dates, show today.
    if ($scope === null && !$filters['from_date'] && !$filters['to_date']) {
        $scope = 'today';
    }

    $stats = array_merge([
        'total'           => 0,
        'today'           => 0,
        'draft'           => 0,
        'confirmed'       => 0,
        'cancelled'       => 0,
        'pending_godown'  => 0,
        'pending_challan' => 0,
        'total_value'     => 0,
    ], $stats ?? []);

    // R22 status chip definitions. The chip's data-status value is
    // sent to the datatable endpoint as `status_chip`. The summary
    // endpoint returns counts for ALL chips regardless of which is
    // active (see SalesInvoiceController::buildInvoiceFilterQuery
    // $excludeStatusChip = true), so the user always sees what's in
    // each bucket without losing their filter context.
    $statusChips = [
        'all'              => ['label' => 'All',                'icon' => 'fa-list'],
        'today'            => ['label' => 'Today',              'icon' => 'fa-calendar-day'],
        'pending_godown'   => ['label' => 'Pending Godown',     'icon' => 'fa-warehouse'],
        'pending_challan'  => ['label' => 'Pending Challan',    'icon' => 'fa-truck'],
        'awaiting_payment' => ['label' => 'Awaiting payment',   'icon' => 'fa-hand-holding-dollar'],
        'draft'            => ['label' => 'Draft',              'icon' => 'fa-pen-to-square'],
        'confirmed'        => ['label' => 'Confirmed',          'icon' => 'fa-circle-check'],
        'cancelled'        => ['label' => 'Cancelled',          'icon' => 'fa-ban'],
        'reversed'         => ['label' => 'Reversed',           'icon' => 'fa-rotate-left'],
    ];
@endphp

<div class="space-y-6">
    {{-- Hero header (amber/orange gradient — showcase spec) --}}
    <div class="bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500 rounded-xl p-6 shadow-lg">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-bold text-white">গোডাউন ও চালান</h1>
                <p class="text-amber-100 text-sm mt-1">Sales Invoices — {{ $title }}</p>
            </div>
            <a href="{{ route('admin.sales.cart') }}" class="inline-flex items-center gap-2 bg-white/20 backdrop-blur-sm hover:bg-white/30 text-white rounded-lg px-4 py-2 text-sm font-medium transition-colors">
                <x-erp.icon name="plus" class="size-4" /> New Sale / নতুন বিক্রয়
            </a>
        </div>
        {{-- Journey stepper --}}
        <div class="mt-6">
            <x-erp.journey-stepper />
        </div>
    </div>

    {{-- Stat cards — showcase design (4 cards) --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <x-erp.stat-card label="Pending Godown" label-bn="গোডাউন বাকি" :value="number_format((int) $stats['pending_godown'])" accent="amber" icon="clock" />
        <x-erp.stat-card label="Pending Challan" label-bn="চালান বাকি" :value="number_format((int) $stats['pending_challan'])" accent="orange" icon="clipboard-list" />
        <x-erp.stat-card label="Total Invoices" label-bn="মোট চালান" :value="number_format((int) $stats['total'])" accent="cyan" icon="file-text" />
        <x-erp.stat-card label="Total Value" label-bn="মোট মূল্য" :value="'৳' . number_format((float) $stats['total_value'], 0)" accent="green" icon="banknote" />
    </div>

    {{-- Filter form (R21 — drives DataTables AJAX params) --}}
    <x-erp.left-accent-card accent="amber" icon="search" title="Filters" title-bn="ফিল্টার">
        <form id="invoiceFilterForm" class="row g-2 align-items-end" autocomplete="off">
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="from_date">From date</label>
                <input type="date" id="from_date" name="from_date" class="form-control form-control-sm"
                       value="{{ $filters['from_date'] }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="to_date">To date</label>
                <input type="date" id="to_date" name="to_date" class="form-control form-control-sm"
                       value="{{ $filters['to_date'] }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="customer_id">Customer</label>
                <select id="customer_id" name="customer_id" class="form-select form-select-sm select2-filter">
                    <option value="">All customers</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}"
                            {{ (string) $filters['customer_id'] === (string) $c->id ? 'selected' : '' }}>
                            {{ $c->customer_code }} — {{ $c->customer_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="branch_id">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select form-select-sm select2-filter">
                    <option value="">All branches</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->id }}"
                            {{ (string) $filters['branch_id'] === (string) $b->id ? 'selected' : '' }}>
                            {{ $b->branch_code }} — {{ $b->branch_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="filterSearch">Smart search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="search" id="filterSearch" name="search" class="form-control"
                           placeholder="Invoice, customer, mobile, branch…"
                           value="{{ $filters['search'] }}" autocomplete="off">
                </div>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="filterSmartSort" name="smart_sort" value="1"
                           {{ $filters['smart_sort'] !== '0' ? 'checked' : '' }}>
                    <label class="form-check-label small" for="filterSmartSort"
                           title="Unpaid first, then oldest invoice date">Smart sort</label>
                </div>
            </div>
            <div class="col-12 d-flex gap-2 justify-content-end flex-wrap">
                <button type="button" id="applyFiltersBtn" class="btn btn-primary btn-sm">
                    <i class="fas fa-filter me-1"></i> Apply
                </button>
                <button type="button" id="clearFiltersBtn" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-eraser me-1"></i> Clear
                </button>
                <a id="csvExportBtn" href="{{ route('admin.sales-invoices.export-csv') }}"
                   class="btn btn-outline-success btn-sm" target="_blank">
                    <i class="fas fa-file-csv me-1"></i> Export CSV
                </a>
            </div>
        </form>
    </x-erp.left-accent-card>

    {{-- R22 + BUG-52: Workflow chips (scope) + Status chips with live counts. --}}
    <x-erp.left-accent-card accent="orange" icon="clipboard-list" title="Filter" title-bn="ফিল্টার" body-class="!py-2">
        <div class="d-flex flex-wrap gap-2 align-items-center" id="statusChipRow">
            <span class="text-muted small me-2">
                <i class="fas fa-filter me-1"></i>Status
                <small class="text-muted fw-normal">(live counts)</small>:
            </span>
            @foreach ($statusChips as $key => $chip)
                @php
                    $isScope = in_array($key, ['today', 'pending_godown', 'pending_challan'], true);
                    $isActive = $isScope
                        ? ($scope === $key)
                        : ($scope === null && $filters['status_chip'] === $key);
                @endphp
                <button type="button"
                        class="btn btn-sm status-chip {{ $isActive ? 'active' : '' }}"
                        @if ($isScope) data-scope="{{ $key }}" @else data-status="{{ $key }}" @endif>
                    <i class="fas {{ $chip['icon'] }} me-1"></i>
                    <span class="chip-label">{{ $chip['label'] }}</span>
                    <span class="chip-count badge bg-secondary ms-1">0</span>
                </button>
            @endforeach
            <input type="hidden" id="status_chip" name="status_chip" value="{{ $filters['status_chip'] }}">
            <input type="hidden" id="scope" name="scope" value="{{ $scope ?? '' }}">
        </div>
    </x-erp.left-accent-card>

    {{-- R21: Invoices table (server-side DataTables) --}}
    <x-erp.left-accent-card accent="cyan" icon="file-text" title="Invoices" title-bn="চালান তালিকা" body-class="!p-0">
            {{-- Phase 1 (UI/UX): Bulk action bar — appears when ≥1 row is checked. --}}
            <div class="px-3 pt-3">
                <div id="invoiceBulkBar"
                     role="region"
                     aria-label="Bulk actions"
                     class="hidden sticky top-0 z-20 bg-amber-50 border border-amber-200 rounded-lg px-4 py-2.5 shadow-sm flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-sm">
                        <span class="inline-flex items-center justify-center size-5 rounded-full bg-amber-500 text-white text-xs font-bold">
                            <i class="fas fa-check" style="font-size:0.6rem;"></i>
                        </span>
                        <span class="font-medium text-amber-900">
                            <span id="bulkSelectedCount" aria-live="polite" aria-atomic="true">0</span>
                            selected / নির্বাচিত
                        </span>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <button type="button" id="bulkCallItADay"
                                class="inline-flex items-center gap-2 rounded-lg bg-orange-500 hover:bg-orange-600 text-white px-3 py-1.5 text-sm font-medium shadow-sm transition-colors disabled:opacity-50 disabled:pointer-events-none">
                            <i class="fas fa-check-circle"></i>
                            Call It A Day
                        </button>
                        <button type="button" id="bulkClear"
                                class="inline-flex items-center gap-2 rounded-lg bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 text-sm font-medium transition-colors">
                            <i class="fas fa-times"></i>
                            Clear
                        </button>
                    </div>
                </div>
                {{-- Screen-reader status region — announced on filter / selection / payment changes --}}
                <div id="srStatus" class="sr-only" aria-live="polite" aria-atomic="true"></div>
            </div>

            {{-- R23: Mobile cards container --}}
            <div id="invoiceCards" class="sales-invoices-mobile-cards"></div>

            <div class="table-responsive sales-invoices-desktop-table">
                <table class="table table-sm table-striped table-hover align-middle mb-0" id="invoiceTable"
                       style="width:100%">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center align-middle" style="width:36px;" data-orderable="false">
                                <input type="checkbox" id="selectAllInvoices"
                                       aria-label="Select all invoices on this page"
                                       class="size-4 rounded border-gray-300 text-amber-600 focus:ring-2 focus:ring-amber-500 focus:ring-offset-0 cursor-pointer" />
                            </th>
                            <th data-data="invoice_code">Code</th>
                            <th data-data="invoice_date">Date</th>
                            <th data-data="customer_name">Customer</th>
                            <th data-data="branch_name">Branch</th>
                            <th data-data="items_count" class="text-end">Items</th>
                            <th data-data="total_amount" class="text-end">Total (Tk)</th>
                            <th data-data="paid_amount" class="text-end">Paid (Tk)</th>
                            <th data-data="due_amount" class="text-end">Due (Tk)</th>
                            <th data-data="status">Status</th>
                            <th data-data="is_soft_hold" class="text-center">Soft Hold?</th>
                            <th data-data="actions" class="text-center" data-orderable="false">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
    </x-erp.left-accent-card>
</div>

{{--
    R19: Inline receive-payment modal shell.
    Body is fetched via AJAX from admin.sales-invoices.receive-modal
    and injected into #receivePaymentModalContent when the user
    clicks the "Receive" button on a row.
--}}
<div class="modal fade" id="receivePaymentModal" tabindex="-1"
     aria-labelledby="receivePaymentModalLabel" aria-hidden="true"
     data-bs-focus="false">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content" id="receivePaymentModalContent">
            {{-- AJAX-fetched _receive_modal_body.blade.php goes here --}}
        </div>
    </div>
</div>

</x-layouts.erp>

@push('scripts')
<script>
$(function () {
    $('.select2-filter').select2({ theme: 'bootstrap-5', width: '100%' });

    // ============================================================
    // ====== R21: Server-side DataTables (smart sort + search) ===
    // ============================================================
    // Mirrors Legacy sales-today-index.js::initDataTable — the
    // table fetches rows via AJAX from the
    // admin.sales-invoices.datatable endpoint. Filter form fields
    // are injected into every AJAX request via the `data` callback
    // so the server can apply the same filter logic.
    //
    // Smart search: the #filterSearch input is debounced (320ms) and
    // triggers a DataTables .search().draw(). On the server, the
    // search value is matched against invoice_code + customer name
    // + customer code + mobile + branch name (see
    // SalesInvoiceController::buildInvoiceFilterQuery).
    //
    // Smart sort: when #filterSmartSort is checked AND the user has
    // not clicked a column header to sort, the server applies
    // "unpaid first (due_amount > 0.01 AND status NOT IN
    // cancelled/reversed), then oldest invoice_date" — mirroring
    // Legacy sales-today-index.js #filterSmartSort.

    var $table = $('#invoiceTable');
    var $search = $('#filterSearch');
    var $form = $('#invoiceFilterForm');
    var $chipInput = $('#status_chip');
    var $scopeInput = $('#scope');
    var searchDebounce = null;

    // ============================================================
    // ====== Phase 1 (UI/UX): route URLs + bulk-bar state =========
    // ============================================================
    // Route URLs emitted from Blade so the JS never hardcodes paths.
    // The cancel URL uses a path built inline (matching the existing
    // receive-modal pattern) because route() would URL-encode a colon
    // placeholder.
    var ROUTES = {
        callItADay: '{{ route("admin.sales-invoices.call-it-a-day") }}',
        csrf:       '{{ csrf_token() }}',
    };
    var $bulkBar = $('#invoiceBulkBar');
    var $bulkCount = $('#bulkSelectedCount');
    var $selectAll = $('#selectAllInvoices');

    // Screen-reader live-region announcer (Phase 1 a11y).
    function announceSR(msg) {
        var $sr = $('#srStatus');
        if ($sr.length) { $sr.text(msg); }
    }

    // Return the currently-checked invoice IDs on the visible page.
    function selectedInvoiceIds() {
        var ids = [];
        $('.row-invoice-checkbox:checked').each(function () {
            ids.push(parseInt($(this).val(), 10) || 0);
        });
        return ids.filter(function (id) { return id > 0; });
    }

    // Show/hide the bulk bar + update the "N selected" count.
    function updateBulkBar() {
        var ids = selectedInvoiceIds();
        $bulkCount.text(ids.length);
        if (ids.length > 0) {
            $bulkBar.removeClass('hidden');
        } else {
            $bulkBar.addClass('hidden');
        }
        // Select-all indeterminate state: if some (but not all) rows
        // on the page are checked, show the indeterminate dash.
        var $boxes = $('.row-invoice-checkbox');
        var total = $boxes.length;
        var checked = $boxes.filter(':checked').length;
        if (total === 0) {
            $selectAll.prop('indeterminate', false).prop('checked', false);
        } else if (checked === total) {
            $selectAll.prop('indeterminate', false).prop('checked', true);
        } else if (checked > 0) {
            $selectAll.prop('indeterminate', true).prop('checked', false);
        } else {
            $selectAll.prop('indeterminate', false).prop('checked', false);
        }
        $('#bulkCallItADay').prop('disabled', ids.length === 0);
    }

    function currentFilterParams() {
        return {
            from_date:   $('#from_date').val(),
            to_date:     $('#to_date').val(),
            customer_id: $('#customer_id').val(),
            branch_id:   $('#branch_id').val(),
            search:      $search.val(),
            smart_sort:  $('#filterSmartSort').is(':checked') ? '1' : '0',
            status_chip: $chipInput.val() || 'all',
            // BUG-52: scope chip (today / pending_godown / pending_challan).
            // Sent alongside status_chip — the server gives it precedence
            // when set (see SalesInvoiceController::buildInvoiceFilterQuery).
            scope:       $scopeInput.val() || '',
        };
    }

    var dt = $table.DataTable({
        processing: true,
        serverSide: true,
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100, 250],
        order: [],   // no default order — let server apply smart sort
        language: {
            emptyTable: 'No sales invoices match your filters.',
            processing: '<i class="fas fa-spinner fa-spin"></i> Loading…',
            search:     '',
            searchPlaceholder: ' ',
        },
        // DataTables' own search box is hidden (we use #filterSearch
        // instead — it's part of the filter form so it persists
        // across page reloads via the URL query string on the index
        // action, and so it lives next to the other filter inputs).
        dom: '<"row mb-2"<"col-md-6"l><"col-md-6 text-end"p>>rt<"row mt-2"<"col-md-6"i><"col-md-6 text-end"p>>',
        ajax: {
            url: '{{ route('admin.sales-invoices.datatable') }}',
            type: 'GET',
            data: function (d) {
                var p = currentFilterParams();
                d.from_date   = p.from_date;
                d.to_date     = p.to_date;
                d.customer_id = p.customer_id;
                d.branch_id   = p.branch_id;
                d.smart_sort  = p.smart_sort;
                d.status_chip = p.status_chip;
                d.scope       = p.scope;
                // If the user has typed something in #filterSearch,
                // use it as the global search value (overrides DT's
                // own search box).
                if (p.search) {
                    d.search = { value: p.search, regex: false };
                }
            },
        },
        columns: [
            {
                // Phase 1 (UI/UX): row checkbox (col 0).
                data: null,
                orderable: false,
                searchable: false,
                className: 'text-center align-middle',
                render: function (data, type, row) {
                    if (type !== 'display') return '';
                    return '<input type="checkbox" class="row-invoice-checkbox size-4 rounded border-gray-300 text-amber-600 focus:ring-2 focus:ring-amber-500 focus:ring-offset-0 cursor-pointer align-middle" '
                         + 'value="' + row.id + '" '
                         + 'aria-label="Select invoice ' + escapeHtml(row.invoice_code || '') + '" />';
                },
            },
            {
                data: 'invoice_code',
                render: function (data, type, row) {
                    if (type !== 'display') return data || '';
                    return '<a href="' + row.show_url + '" class="fw-semibold text-decoration-none">' +
                           escapeHtml(data || '') + '</a>';
                },
            },
            {
                data: 'invoice_date',
                render: function (data, type, row) {
                    if (type !== 'display' || !data) return data || '';
                    var p = String(data).split('-');
                    return p.length === 3 ? p[2] + '-' + p[1] + '-' + p[0] : data;
                },
            },
            {
                data: 'customer_name',
                render: function (data, type, row) {
                    if (type !== 'display') return data || '';
                    if (!data) return '<span class="text-muted">—</span>';
                    var html = '<span class="fw-semibold">' + escapeHtml(data) + '</span>';
                    if (row.customer_code) {
                        html += '<div class="small text-muted">' + escapeHtml(row.customer_code) + '</div>';
                    }
                    return html;
                },
            },
            {
                data: 'branch_name',
                render: function (data, type, row) {
                    if (type !== 'display') return data || '';
                    return data ? escapeHtml(data) : '<span class="text-muted">—</span>';
                },
            },
            {
                data: 'items_count',
                className: 'text-end',
                render: function (data, type, row) {
                    if (type !== 'display') return data || 0;
                    return numberFormat(data);
                },
            },
            {
                data: 'total_amount',
                className: 'text-end',
                render: function (data, type, row) {
                    if (type !== 'display') return data || 0;
                    return numberFormat(data, 2);
                },
            },
            {
                data: 'paid_amount',
                className: 'text-end',
                render: function (data, type, row) {
                    if (type !== 'display') return data || 0;
                    return numberFormat(data, 2);
                },
            },
            {
                data: 'due_amount',
                className: 'text-end',
                render: function (data, type, row) {
                    if (type !== 'display') return data || 0;
                    var due = parseFloat(data || 0);
                    if (due > 0.01) {
                        return '<span class="text-danger fw-semibold">' + numberFormat(due, 2) + '</span>';
                    }
                    return '<span class="text-success">0.00</span>';
                },
            },
            {
                data: 'status',
                render: function (data, type, row) {
                    if (type !== 'display') return data || '';
                    return statusBadgeHtml(data, row && row.is_reversed);
                },
            },
            {
                data: 'is_soft_hold',
                className: 'text-center',
                render: function (data, type, row) {
                    if (type !== 'display') return data ? 1 : 0;
                    if (data) {
                        return '<span class="badge bg-danger-subtle text-danger" title="On soft hold">' +
                               '<i class="fas fa-hand"></i> Yes</span>';
                    }
                    return '<span class="text-muted">—</span>';
                },
            },
            {
                // Phase 1 (UI/UX): per-row actions — full Legacy parity.
                // View / Edit / Receive / Call-it-a-day / Print inline +
                // Cancel in an overflow dropdown. Uses .rc-action-btn
                // (defined in the <style> block below) for compact icon
                // buttons matching the <x-erp.action-button> spec.
                data: null,
                orderable: false,
                className: 'text-center text-nowrap',
                render: function (data, type, row) {
                    if (type !== 'display') return '';
                    var code = escapeHtml(row.invoice_code || '');
                    var html = '<div class="d-flex gap-1 justify-content-center align-items-center">';

                    // View (always)
                    html += '<a href="' + row.show_url + '" class="rc-action-btn rc-action-view" '
                         +  'title="View" aria-label="View invoice ' + code + '">'
                         +  '<i class="fas fa-eye"></i></a>';

                    // Edit (draft only)
                    if (row.show_edit && row.edit_url) {
                        html += '<a href="' + row.edit_url + '" class="rc-action-btn rc-action-edit" '
                             +  'title="Edit draft" aria-label="Edit invoice ' + code + '">'
                             +  '<i class="fas fa-pen"></i></a>';
                    }

                    // Receive payment (due > 0)
                    if (row.show_receive) {
                        html += '<button type="button" class="rc-action-btn rc-action-receive btn-receive-payment" '
                             +  'title="Receive payment" '
                             +  'aria-label="Receive payment for invoice ' + code + '" '
                             +  'data-invoice-id="' + row.id + '" '
                             +  'data-invoice-code="' + code + '">'
                             +  '<i class="fas fa-hand-holding-dollar"></i></button>';
                    }

                    // Call it a day (paid, not yet called)
                    if (row.show_call_a_day) {
                        html += '<button type="button" class="rc-action-btn rc-action-callitaday btn-call-it-a-day" '
                             +  'title="Call it a day" '
                             +  'aria-label="Call it a day for invoice ' + code + '" '
                             +  'data-invoice-id="' + row.id + '" '
                             +  'data-invoice-code="' + code + '">'
                             +  '<i class="fas fa-check-circle"></i></button>';
                    }

                    // Print invoice (confirmed + not reversed)
                    if (row.show_print && row.print_invoice_url) {
                        html += '<a href="' + row.print_invoice_url + '" target="_blank" rel="noopener" '
                             +  'class="rc-action-btn rc-action-print" '
                             +  'title="Print invoice" aria-label="Print invoice ' + code + '">'
                             +  '<i class="fas fa-print"></i></a>';
                    }

                    // Overflow: Cancel (draft only) — kept in a dropdown
                    // so the row stays narrow.
                    if (row.show_cancel) {
                        html += '<div class="dropdown d-inline-block">'
                             +  '<button type="button" class="rc-action-btn" data-bs-toggle="dropdown" '
                             +  'aria-expanded="false" title="More" '
                             +  'aria-label="More actions for invoice ' + code + '">'
                             +  '<i class="fas fa-ellipsis-h"></i></button>'
                             +  '<ul class="dropdown-menu dropdown-menu-end shadow-lg rounded-md border border-gray-200 bg-white py-1" style="min-width:12rem;">'
                             +  '<li><button type="button" class="dropdown-item text-danger btn-cancel-invoice" '
                             +  'data-invoice-id="' + row.id + '" data-invoice-code="' + code + '">'
                             +  '<i class="fas fa-ban me-2"></i>Cancel invoice</button></li>'
                             +  '</ul></div>';
                    }

                    html += '</div>';
                    return html;
                },
            },
        ],
        drawCallback: function () {
            // R23: Render mobile cards from the current page's data.
            renderMobileCards(this.api());

            // Phase 1 (UI/UX): DataTables redraws the tbody on every
            // page change / ajax.reload, so per-row checkboxes are new
            // DOM nodes. Reset the select-all + bulk bar to reflect the
            // fresh (unchecked) checkboxes. If the user had a selection
            // that was just "called it a day", the bar hides itself.
            $selectAll.prop('indeterminate', false).prop('checked', false);
            updateBulkBar();
        },
    });

    // ============================================================
    // ====== R22: Status chips with live counts ===================
    // ============================================================
    // Clicking a chip sets the hidden #status_chip input + reloads
    // the DataTable. The summary endpoint is called separately (and
    // always returns counts for ALL chips, regardless of which chip
    // is active) so the user can see the size of each bucket before
    // switching. Mirrors Legacy sales-today-index.js::refreshSummary.

    var summaryDebounce = null;

    function scheduleSummary() {
        clearTimeout(summaryDebounce);
        summaryDebounce = setTimeout(refreshSummary, 280);
    }

    function refreshSummary() {
        var p = currentFilterParams();
        $.ajax({
            url: '{{ route('admin.sales-invoices.summary') }}',
            type: 'GET',
            data: {
                from_date:   p.from_date,
                to_date:     p.to_date,
                customer_id: p.customer_id,
                branch_id:   p.branch_id,
                search:      p.search,
            },
            dataType: 'json',
        }).done(function (data) {
            if (!data || data.status === 'error') return;
            updateChipCounts(data);
        }).fail(function () { /* silent — non-critical */ });
    }

    function updateChipCounts(data) {
        var map = {
            all:              data.total ?? 0,
            today:            data.today ?? 0,
            pending_godown:   data.pending_godown ?? 0,
            pending_challan:  data.pending_challan ?? 0,
            awaiting_payment: data.awaiting_payment ?? 0,
            draft:            data.draft ?? 0,
            confirmed:        data.confirmed ?? 0,
            cancelled:        data.cancelled ?? 0,
            reversed:         data.reversed ?? 0,
        };
        $('.status-chip').each(function () {
            // BUG-52: chip may carry either data-status (status chip) or
            // data-scope (workflow chip). Look up by whichever is set.
            var key = $(this).data('status') || $(this).data('scope');
            if (key) {
                $(this).find('.chip-count').text(map[key] ?? 0);
            }
        });
    }

    // BUG-52: unified click handler — supports both data-status (status
    // chip) and data-scope (workflow chip) attributes. Clicking a scope
    // chip clears status_chip so the scope takes precedence; clicking a
    // status chip clears scope. 'all' is treated as a status chip that
    // also clears scope (so the user sees everything).
    $('.status-chip').on('click', function () {
        var statusKey = $(this).data('status');
        var scopeKey  = $(this).data('scope');

        if (scopeKey) {
            $scopeInput.val(scopeKey);
            $chipInput.val('all');
        } else {
            $scopeInput.val('');
            $chipInput.val(statusKey);
        }
        $('.status-chip').removeClass('active');
        $(this).addClass('active');
        dt.ajax.reload();
        scheduleSummary();
        updateExportLink();
    });

    // ============================================================
    // ====== Filter form wiring ===================================
    // ============================================================
    // The form is NOT submitted traditionally — it's a state holder
    // for the DataTables AJAX data callback. Apply reloads the
    // table; Clear resets all inputs + reloads.

    $('#applyFiltersBtn').on('click', function () {
        dt.ajax.reload();
        scheduleSummary();
        updateExportLink();
    });

    $('#clearFiltersBtn').on('click', function () {
        $('#from_date, #to_date').val('');
        $('#customer_id, #branch_id').val('').trigger('change');
        $search.val('');
        $('#filterSmartSort').prop('checked', true);
        // BUG-52: clear both scope and status_chip on reset.
        $scopeInput.val('');
        $chipInput.val('all');
        $('.status-chip').removeClass('active');
        $('.status-chip[data-status="all"]').addClass('active');
        dt.ajax.reload();
        scheduleSummary();
        updateExportLink();
    });

    // Debounced smart search — 320ms feels instant without spamming
    // the server on every keystroke. Mirrors Legacy debounce in
    // sales-today-index.js::bindFilterUi.
    $search.on('input', function () {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(function () {
            dt.ajax.reload();
            scheduleSummary();
            updateExportLink();
        }, 320);
    });

    // Date / customer / branch / smart-sort changes trigger an
    // immediate reload (these are discrete picks, not free text).
    $('#from_date, #to_date, #customer_id, #branch_id, #filterSmartSort').on('change', function () {
        dt.ajax.reload();
        scheduleSummary();
        updateExportLink();
    });

    // CSV export: pass current filter params (incl. status_chip) to export URL
    function updateExportLink() {
        var p = currentFilterParams();
        var params = new URLSearchParams();
        ['from_date', 'to_date', 'customer_id', 'branch_id', 'status', 'search'].forEach(function (f) {
            if (p[f]) params.set(f, p[f]);
        });
        if (p.status_chip && p.status_chip !== 'all') {
            // Map chip back to a plain status for the CSV exporter
            // (which only understands the simple status filter).
            if (['draft', 'confirmed', 'cancelled'].indexOf(p.status_chip) !== -1) {
                params.set('status', p.status_chip);
            }
        }
        var base = $('#csvExportBtn').attr('href').split('?')[0];
        $('#csvExportBtn').attr('href', base + '?' + params.toString());
    }

    // ============================================================
    // ====== Phase 1 (UI/UX): checkboxes + Call-It-A-Day ==========
    // ============================================================
    // Delegated handlers (survive DataTables redraws). The bulk bar
    // appears when ≥1 row is checked; "Call It A Day" flags invoices
    // as collected so they vanish from the Today list on redraw.

    // Per-row checkbox → update the bulk bar + select-all state.
    $table.on('change', '.row-invoice-checkbox', function () {
        updateBulkBar();
        announceSR($(this).is(':checked') ? 'Row selected' : 'Row unselected');
    });

    // Select-all header checkbox → toggle every checkbox on the page.
    $selectAll.on('change', function () {
        var checked = $(this).is(':checked');
        $('.row-invoice-checkbox').prop('checked', checked);
        updateBulkBar();
        announceSR(checked ? 'All rows on this page selected' : 'Selection cleared');
    });

    // Bulk "Clear" → uncheck everything + hide the bar.
    $('#bulkClear').on('click', function () {
        $('.row-invoice-checkbox').prop('checked', false);
        $selectAll.prop('indeterminate', false).prop('checked', false);
        updateBulkBar();
    });

    // Bulk "Call It A Day" → confirm → AJAX POST → redraw.
    $('#bulkCallItADay').on('click', function () {
        var ids = selectedInvoiceIds();
        if (ids.length === 0) return;
        confirmCallItADay(ids, 'Call it a day on ' + ids.length + ' invoice(s)?',
            'They will be removed from your daily collection list. This does NOT cancel the invoice or affect the ledger.');
    });

    // Per-row "Call It A Day" (delegated — survives redraws).
    $(document).on('click', '.btn-call-it-a-day', function () {
        var id = parseInt($(this).data('invoice-id'), 10) || 0;
        var code = String($(this).data('invoice-code') || '');
        if (!id) return;
        confirmCallItADay([id],
            'Call it a day for ' + escapeHtml(code) + '?',
            'It will be removed from your daily collection list. This does NOT cancel the invoice or affect the ledger.');
    });

    // Per-row "Cancel invoice" (delegated — lives in the overflow dropdown).
    $(document).on('click', '.btn-cancel-invoice', function () {
        var id = parseInt($(this).data('invoice-id'), 10) || 0;
        var code = String($(this).data('invoice-code') || '');
        if (!id) return;
        cancelInvoice(id, code);
    });

    // Shared "Call It A Day" confirm → POST → redraw flow.
    function confirmCallItADay(ids, title, text) {
        Swal.fire({
            title: title,
            html: text,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check-circle me-1"></i>Yes, call it a day',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#ea580c', // orange-600
        }).then(function (r) {
            if (!r.isConfirmed) return;
            callItADay(ids);
        });
    }

    // AJAX POST to admin.sales-invoices.call-it-a-day → redraw.
    function callItADay(ids) {
        $.ajax({
            url: ROUTES.callItADay,
            method: 'POST',
            data: { _token: ROUTES.csrf, invoice_ids: ids },
            dataType: 'json',
        }).done(function (data) {
            var count = (data && data.updated_count) || 0;
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: count + ' invoice(s) called it a day ✓',
                showConfirmButton: false,
                timer: 2200,
            });
            announceSR(count + ' invoices called it a day.');
            dt.ajax.reload();
            scheduleSummary();
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || xhr.statusText || 'Server error';
            Swal.fire({
                icon: 'error',
                title: 'Could not call it a day',
                html: escapeHtml(msg),
            });
        });
    }

    // AJAX POST to admin.sales-invoices.cancel → redraw.
    function cancelInvoice(id, code) {
        Swal.fire({
            title: 'Cancel invoice ' + escapeHtml(code) + '?',
            html: 'This reverses the sale: stock returns to inventory, the GL is reversed, and the customer ledger is credited. <b>This cannot be undone.</b>',
            icon: 'warning',
            input: 'textarea',
            inputPlaceholder: 'Reason for cancellation (required, min 5 chars)…',
            inputValidator: function (v) {
                if (!v || String(v).trim().length < 5) {
                    return 'Reason must be at least 5 characters.';
                }
                return null;
            },
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-ban me-1"></i>Yes, cancel it',
            cancelButtonText: 'Keep it',
            confirmButtonColor: '#dc2626', // red-600
        }).then(function (r) {
            if (!r.isConfirmed) return;
            // Cancel URL built inline (matches the receive-modal AJAX
            // pattern) — avoids route() URL-encoding a placeholder.
            var url = '/admin/sales-invoices/' + id + '/cancel';
            $.ajax({
                url: url,
                method: 'POST',
                data: { _token: ROUTES.csrf, cancel_reason: r.value },
                dataType: 'json',
            }).done(function () {
                Swal.fire({
                    toast: true, position: 'top-end', icon: 'success',
                    title: 'Invoice ' + escapeHtml(code) + ' cancelled.',
                    showConfirmButton: false, timer: 2200,
                });
                announceSR('Invoice ' + code + ' cancelled.');
                dt.ajax.reload();
                scheduleSummary();
            }).fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || xhr.statusText || 'Server error';
                Swal.fire({ icon: 'error', title: 'Could not cancel', html: escapeHtml(msg) });
            });
        });
    }

    // ============================================================
    // ====== R23: Mobile cards variant ============================
    // ============================================================
    // On narrow screens (max-width: 767.98px), the desktop table is
    // hidden by CSS and the #invoiceCards container is shown. We
    // populate it from the DataTables API on every draw — same
    // data, just a different layout. Mirrors Legacy
    // sales-today-index.js::renderInvoiceCards.

    function renderMobileCards(api) {
        var $cards = $('#invoiceCards');
        if (!$cards.length) return;
        // Only render when window is narrow OR the table is hidden
        // (some browsers fire drawCallback before CSS applies).
        if (window.innerWidth >= 768) {
            $cards.empty();
            return;
        }
        var data = api.rows({ page: 'current' }).data();
        if (!data || data.length === 0) {
            $cards.html(
                '<div class="text-center text-muted py-4">' +
                    '<i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>' +
                    '<p class="mb-0">No sales invoices match your filters.</p>' +
                '</div>'
            );
            return;
        }
        var html = '';
        data.each(function (row) {
            var statusCls = row.is_reversed ? 'card-reversed'
                : row.status === 'cancelled' ? 'card-cancelled'
                : (parseFloat(row.due_amount || 0) > 0.01 ? 'card-due' : 'card-paid');
            var due = parseFloat(row.due_amount || 0);
            var dateParts = String(row.invoice_date || '').split('-');
            var dateStr = dateParts.length === 3 ? dateParts[2] + '-' + dateParts[1] + '-' + dateParts[0] : (row.invoice_date || '');

            html += '<div class="sales-invoice-card ' + statusCls + '">';
            html +=   '<div class="d-flex justify-content-between align-items-start">';
            html +=     '<a href="' + row.show_url + '" class="fw-semibold text-decoration-none">' + escapeHtml(row.invoice_code || '') + '</a>';
            html +=     '<span class="text-muted small">' + escapeHtml(dateStr) + '</span>';
            html +=   '</div>';
            html +=   '<div class="mt-1 fw-semibold">' + escapeHtml(row.customer_name || '—') + '</div>';
            html +=   '<div class="small text-muted">' + escapeHtml(row.branch_name || '') + '</div>';
            html +=   '<div class="mt-2 d-flex justify-content-between align-items-center flex-wrap gap-1">';
            html +=     statusBadgeHtml(row.status, row.is_reversed);
            html +=     '<div class="text-end">';
            html +=       '<div class="small text-muted">Total Tk ' + numberFormat(row.total_amount, 2) + '</div>';
            if (due > 0.01) {
                html +=     '<strong class="text-danger">Due Tk ' + numberFormat(due, 2) + '</strong>';
            } else {
                html +=     '<strong class="text-success">Paid</strong>';
            }
            html +=       '</div>';
            html +=     '</div>';
            html +=   '</div>';
            if (row.is_soft_hold) {
                html += '<div class="mt-1"><span class="badge bg-danger-subtle text-danger">' +
                        '<i class="fas fa-hand me-1"></i>Soft hold</span></div>';
            }
            html +=   '<div class="mt-2 d-flex gap-1 flex-wrap align-items-center">';
            html +=     '<a href="' + row.show_url + '" class="btn btn-sm btn-outline-secondary">' +
                            '<i class="fas fa-eye"></i> View</a>';
            if (row.show_receive) {
                html +=   '<button type="button" class="btn btn-sm btn-success btn-receive-payment" ' +
                          'data-invoice-id="' + row.id + '" ' +
                          'data-invoice-code="' + escapeHtml(row.invoice_code) + '">' +
                          '<i class="fas fa-hand-holding-dollar me-1"></i>Receive</button>';
            }
            // Phase 1 (UI/UX): Call-it-a-day on mobile cards.
            if (row.show_call_a_day) {
                html +=   '<button type="button" class="btn btn-sm btn-outline-warning btn-call-it-a-day" ' +
                          'data-invoice-id="' + row.id + '" ' +
                          'data-invoice-code="' + escapeHtml(row.invoice_code) + '" title="Call it a day">' +
                          '<i class="fas fa-check-circle me-1"></i>Done</button>';
            }
            // Phase 1 (UI/UX): Print on mobile cards.
            if (row.show_print && row.print_invoice_url) {
                html +=   '<a href="' + row.print_invoice_url + '" target="_blank" rel="noopener" ' +
                          'class="btn btn-sm btn-outline-secondary" title="Print invoice">' +
                          '<i class="fas fa-print"></i></a>';
            }
            html +=   '</div>';
            html += '</div>';
        });
        $cards.html(html);
    }

    // Re-render mobile cards on resize (debounced).
    var resizeDebounce = null;
    $(window).on('resize', function () {
        clearTimeout(resizeDebounce);
        resizeDebounce = setTimeout(function () {
            renderMobileCards(dt);
        }, 180);
    });

    // ============================================================
    // ====== R19: Inline receive-payment modal ====================
    // ============================================================
    // (Delegated handler — survives DataTables redraws.)
    var $receiveModal = $('#receivePaymentModal');
    var receiveModalBs = null;
    var $modalContent = $('#receivePaymentModalContent');

    function getReceiveModalBs() {
        if (!receiveModalBs) {
            receiveModalBs = bootstrap.Modal.getOrCreateInstance($receiveModal[0], {
                backdrop: 'static',
                keyboard: false,
            });
        }
        return receiveModalBs;
    }

    $(document).on('click', '.btn-receive-payment', function () {
        var invoiceId = $(this).data('invoice-id');
        if (!invoiceId) return;
        $modalContent.html(
            '<div class="modal-body text-center py-5">' +
                '<i class="fas fa-spinner fa-spin fa-2x text-primary mb-3"></i>' +
                '<div class="text-muted">Loading payment form…</div>' +
            '</div>'
        );
        getReceiveModalBs().show();

        $.ajax({
            url: '/admin/sales-invoices/' + invoiceId + '/receive-modal',
            method: 'GET',
            dataType: 'html',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function (html) {
            $modalContent.html(html);
            initReceiveModalBody();
        }).fail(function (xhr) {
            $modalContent.html(
                '<div class="modal-body text-center py-5">' +
                    '<i class="fas fa-exclamation-triangle fa-2x text-danger mb-3"></i>' +
                    '<div class="text-danger">Could not load payment form.</div>' +
                    '<div class="small text-muted mt-2">' + (xhr.statusText || 'Server error') + '</div>' +
                    '<button type="button" class="btn btn-outline-secondary btn-sm mt-3" data-bs-dismiss="modal">Close</button>' +
                '</div>'
            );
        });
    });

    function initReceiveModalBody() {
        var $body = $modalContent.find('.receive-modal-body');
        if (!$body.length) return;

        var balance = parseFloat($body.data('balance')) || 0;
        var $form = $('#srpForm');
        var $amount = $('#srpAmount');
        var $hint = $('#srpAmountHint');
        var $submit = $('#srpSubmit');
        var $bankPanel = $('#srpBankPanel');
        var $bankId = $('#srpBankId');
        var $allocHidden = $('#srpAllocAmountHidden');

        function parseNum(v) {
            var n = parseFloat(String(v).replace(/,/g, ''));
            return Number.isFinite(n) ? n : 0;
        }
        function fmtMoney(n) {
            return 'Tk ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function validateAmount() {
            var amt = parseNum($amount.val());
            if (balance <= 0) {
                $submit.prop('disabled', true);
                $hint.html('<i class="fas fa-check-circle text-success me-1"></i>Already fully paid.').removeClass('text-danger').addClass('text-success');
                return false;
            }
            if (amt <= 0) {
                $submit.prop('disabled', true);
                $hint.html('<i class="fas fa-info-circle text-warning me-1"></i>Enter an amount greater than zero.').removeClass('text-success').addClass('text-danger');
                return false;
            }
            if (amt > balance + 0.001) {
                $submit.prop('disabled', true);
                $hint.html('<i class="fas fa-triangle-exclamation text-danger me-1"></i>Amount cannot exceed balance due (' + fmtMoney(balance) + ').').addClass('text-danger');
                return false;
            }
            $submit.prop('disabled', false);
            $hint.html('<i class="fas fa-check text-success me-1"></i>Balance after this payment: ' + fmtMoney(Math.max(0, balance - amt))).removeClass('text-danger');
            $allocHidden.val(amt.toFixed(2));
            return true;
        }

        $amount.on('input change', validateAmount);
        validateAmount();

        $('input[name="payment_mode"]').on('change', function () {
            var mode = $(this).val();
            var showBank = (mode === 'bank' || mode === 'mobile_banking' || mode === 'cheque');
            $bankPanel.toggle(showBank);
            $bankId.prop('required', showBank && mode === 'bank');
        });

        $modalContent.find('[data-quick]').on('click', function () {
            var kind = $(this).data('quick');
            var val = 0;
            if (kind === 'quarter') val = balance / 4;
            else if (kind === 'half') val = balance / 2;
            else if (kind === 'full') val = balance;
            else if (kind === 'clear') val = 0;
            $amount.val(val.toFixed(2)).trigger('input');
        });

        $submit.on('click', function () {
            if ($submit.prop('disabled')) return;
            if (!validateAmount()) return;
            var amt = parseNum($amount.val());
            if (amt > balance) {
                Swal.fire({
                    title: 'Over-payment?',
                    html: 'Amount entered (' + fmtMoney(amt) + ') exceeds balance due (' + fmtMoney(balance) + ').<br>The excess will be applied to the customer\'s account as advance.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, proceed',
                    cancelButtonText: 'Cancel'
                }).then(function (r) {
                    if (r.isConfirmed) doSubmit();
                });
                return;
            }
            doSubmit();
        });

        function doSubmit() {
            $submit.prop('disabled', true).html(
                '<i class="fas fa-spinner fa-spin me-1"></i>Processing…'
            );
            // Phase 1 (UI/UX): AJAX submit — the user STAYS on the
            // index page (no redirect). The controller returns JSON
            // when expectsJson() / ajax() is true (X-Requested-With).
            $.ajax({
                url: $form.attr('action'),
                method: 'POST',
                data: $form.serialize(),
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }).done(function (data) {
                // Close the receive modal — payment succeeded.
                getReceiveModalBs().hide();

                var isFullyPaid = !!(data && data.is_fully_paid);
                var invoiceId   = (data && data.invoice_id) || 0;
                var printUrl    = (data && data.print_receipt_url) || '';
                var payCode     = (data && data.payment_code) || '';

                announceSR('Payment ' + payCode + ' recorded.');
                // Refresh the table so due/paid columns update.
                dt.ajax.reload();
                scheduleSummary();

                // Success dialog with "Print receipt" button.
                Swal.fire({
                    icon: 'success',
                    title: 'Payment recorded ✓',
                    html: payCode
                        ? '<div class="small text-muted">Payment <b>' + escapeHtml(payCode) + '</b> recorded'
                          + (isFullyPaid ? ' — invoice is now fully paid.' : '.')
                          + '</div>'
                        : '',
                    showCancelButton: !!printUrl,
                    confirmButtonText: printUrl
                        ? '<i class="fas fa-print me-1"></i>Print receipt'
                        : 'OK',
                    cancelButtonText: 'Close',
                    confirmButtonColor: '#059669', // green-600
                }).then(function (r) {
                    if (r.isConfirmed && printUrl) {
                        window.open(printUrl, '_blank', 'noopener');
                    }
                    // Follow-up: if fully paid, offer "Call it a day?".
                    if (isFullyPaid && invoiceId > 0) {
                        confirmCallItADay([invoiceId],
                            'Call it a day?',
                            'This invoice is now fully paid. Remove it from your daily collection list?');
                    }
                });
            }).fail(function (xhr) {
                var msg;
                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    // Laravel validation errors — join the first message per field.
                    var errs = xhr.responseJSON.errors;
                    msg = Object.keys(errs).map(function (k) { return errs[k].join(' '); }).join(' ');
                } else {
                    msg = (xhr.responseJSON && xhr.responseJSON.message)
                        || xhr.statusText
                        || 'Server error';
                }
                Swal.fire({
                    icon: 'error',
                    title: 'Payment failed',
                    html: escapeHtml(msg),
                });
            }).always(function () {
                $submit.prop('disabled', false).html(
                    '<i class="fas fa-check me-1"></i>Receive payment'
                );
            });
        }
    }

    // ============================================================
    // ====== Shared helpers =======================================
    // ============================================================
    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    function numberFormat(n, decimals) {
        decimals = (decimals === undefined) ? 0 : decimals;
        var parts = parseFloat(n || 0).toFixed(decimals).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return parts.join('.');
    }
    function statusBadgeHtml(status, isReversed) {
        if (isReversed) {
            return '<span class="badge bg-danger-subtle text-danger">' +
                   '<i class="fas fa-rotate-left me-1"></i>Reversed</span>';
        }
        var map = {
            draft:     '<span class="badge bg-warning-subtle text-warning"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            confirmed: '<span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Confirmed</span>',
            cancelled: '<span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-ban me-1"></i>Cancelled</span>',
        };
        return map[status] || '<span class="badge bg-light text-dark">' + escapeHtml(status || '') + '</span>';
    }

    // ============================================================
    // ====== Initial load =========================================
    // ============================================================
    refreshSummary();
    updateExportLink();
});
</script>
@endpush

@push('css')
<link rel="stylesheet" href="{{ asset('assets/css/sales-today-index.css') }}">
<style>
    /* R21/R22/R23: Sales-invoices index page polish.
       Reuses the Legacy sales-today-index.css for the chip + mobile
       card visuals (already ported to Laravel assets). The styles
       below are page-specific additions. */

    .sales-invoices-app { padding-bottom: 2rem; }

    /* Status chips (R22). The base .status-chip class lives in
       sales-today-index.css; here we add the Laravel-specific
       Bootstrap-5 button integration so the chips look like buttons
       but inherit the chip colour rules. */
    .status-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        border-radius: 999px;
        padding: 0.25rem 0.75rem;
        font-size: 0.82rem;
        font-weight: 500;
        background: #f1f5f9;
        color: #334155;
        border: 1px solid transparent;
        cursor: pointer;
        transition: background-color 0.12s ease, color 0.12s ease, border-color 0.12s ease;
    }
    .status-chip:hover { background: #e2e8f0; }
    .status-chip.active {
        background: #4f46e5;
        color: #fff;
    }
    .status-chip .chip-count {
        font-size: 0.7rem;
        font-weight: 600;
        padding: 0.15rem 0.4rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.25);
    }
    .status-chip:not(.active) .chip-count { background: #fff; color: #475569; }
    .status-chip[data-status="awaiting_payment"].active { background: #dc2626; }
    .status-chip[data-status="draft"].active            { background: #d97706; }
    .status-chip[data-status="confirmed"].active        { background: #16a34a; }
    .status-chip[data-status="cancelled"].active        { background: #64748b; }
    .status-chip[data-status="reversed"].active         { background: #b91c1c; }
    /* BUG-52: scope chips — distinct colors so the workflow queue
       chips are visually distinguishable from the status chips. */
    .status-chip[data-scope="today"].active           { background: #4f46e5; }
    .status-chip[data-scope="pending_godown"].active  { background: #0891b2; }
    .status-chip[data-scope="pending_challan"].active { background: #7c3aed; }

    /* R23: Mobile cards variant. Hidden on desktop, shown on mobile.
       The cards themselves are styled below to match the Legacy
       sales-today-index.css .sales-today-mobile-card pattern. */
    .sales-invoices-mobile-cards { display: none; }
    @media (max-width: 767.98px) {
        .sales-invoices-mobile-cards { display: block; }
        .sales-invoices-desktop-table { display: none; }
    }

    .sales-invoice-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-left-width: 4px;
        border-radius: 10px;
        padding: 0.75rem 0.9rem;
        margin-bottom: 0.6rem;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
    }
    .sales-invoice-card.card-due       { border-left-color: #dc2626; }
    .sales-invoice-card.card-paid      { border-left-color: #16a34a; }
    .sales-invoice-card.card-cancelled { border-left-color: #64748b; opacity: 0.85; }
    .sales-invoice-card.card-reversed  { border-left-color: #b91c1c; background: #fef2f2; }

    /* R19: Inline receive-payment modal polish
       (kept inline to avoid an extra asset file). */
    #receivePaymentModal .modal-body { max-height: 70vh; overflow-y: auto; }
    #receivePaymentModal .form-control-sm,
    #receivePaymentModal .form-select-sm { font-size: 0.875rem; }
    #receivePaymentModal .btn-sm { font-size: 0.8rem; }

    /* DataTables processing overlay — center the spinner. */
    div.dataTables_wrapper div.dataTables_processing {
        top: 50%; transform: translateY(-50%);
        background: rgba(255,255,255,0.9);
        border-radius: 8px;
        padding: 1rem 2rem;
    }

    /* ============================================================
       Phase 1 (UI/UX): compact per-row action buttons (.rc-action-btn)
       Matches the <x-erp.action-button> spec — Tailwind-equivalent
       sizing/colors. Used by the DataTable actions column + mobile
       cards. Defined here (not in rc-erp.css) because the buttons
       are rendered client-side by DataTables JS.
       ============================================================ */
    .rc-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2rem;            /* size-8 */
        height: 2rem;
        border-radius: 0.375rem; /* rounded-md */
        border: 1px solid #e5e7eb; /* border-gray-200 */
        background: #fff;
        color: #4b5563;          /* text-gray-600 */
        transition: background-color 0.12s ease, color 0.12s ease, border-color 0.12s ease;
        text-decoration: none;
        font-size: 0.8rem;
        line-height: 1;
        cursor: pointer;
    }
    .rc-action-btn:hover { background: #f9fafb; color: #1f2937; border-color: #d1d5db; }
    .rc-action-btn.rc-action-edit:hover          { background: #fffbeb; color: #b45309; border-color: #fcd34d; } /* amber */
    .rc-action-btn.rc-action-receive:hover       { background: #f0fdf4; color: #15803d; border-color: #86efac; } /* green */
    .rc-action-btn.rc-action-callitaday:hover    { background: #fff7ed; color: #c2410c; border-color: #fdba74; } /* orange */
    .rc-action-btn.rc-action-cancel:hover        { background: #fef2f2; color: #b91c1c; border-color: #fca5a5; } /* red */
    .rc-action-btn.rc-action-print:hover         { background: #f9fafb; color: #1f2937; border-color: #d1d5db; }
    .rc-action-btn:focus-visible {
        outline: 2px solid #f59e9b;
        outline-offset: 1px;
    }

    /* sr-only fallback — Tailwind's sr-only utility may not be in the
       built CSS if no other element uses it. This guarantees the
       screen-reader region is visually hidden. */
    .sr-only {
        position: absolute;
        width: 1px; height: 1px;
        padding: 0; margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    /* Bulk action bar: slide-in animation when it appears. */
    #invoiceBulkBar:not(.hidden) {
        animation: rcBulkBarSlide 0.18s ease-out;
    }
    @media (prefers-reduced-motion: reduce) {
        #invoiceBulkBar:not(.hidden) { animation: none; }
    }
    @keyframes rcBulkBarSlide {
        from { opacity: 0; transform: translateY(-6px); }
        to   { opacity: 1; transform: translateY(0); }
    }
</style>
@endpush
