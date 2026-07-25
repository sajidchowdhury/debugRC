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

<div class="space-y-6 sales-invoices-app">
    {{-- Hero header (amber/orange gradient — showcase spec) --}}
    <div class="bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500 rounded-xl p-6 shadow-lg">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-bold text-white">গোডাউন ও চালান</h1>
                <p class="text-amber-100 text-sm mt-1">
                    <span id="heroInvoiceCount"
                          aria-live="polite" aria-atomic="true">{{ number_format((int) ($stats['total'] ?? 0)) }}</span>
                    invoices on your collection list
                </p>
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

    {{-- Filter form (R21 — drives DataTables AJAX params).
        Phase 6 (UI/UX): collapsible on mobile (saves vertical space),
        always-expanded on desktop via CSS media query. --}}
    <x-erp.collapsible-card id="filterCard" accent="amber" icon="search" title="Filters" title-bn="ফিল্টার">
        {{-- Phase 2 (UI/UX): one-click date presets — Today / Yesterday / Last 7 days / This month / Custom.
            The preset resolves to concrete from_date/to_date values on the client (see applyDatePreset). --}}
        <div class="mb-3 flex items-center gap-3 flex-wrap">
            <span class="text-xs font-medium text-gray-500 inline-flex items-center gap-1">
                <i class="fas fa-calendar-day"></i> Quick dates
            </span>
            <x-erp.date-presets id="datePresets" />
        </div>
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
    </x-erp.collapsible-card>

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
                    // Build the data-* attribute as a pre-escaped string so we
                    // avoid an inline conditional directive inside the button
                    // tag (Blade can mis-compile inline if/else/endif that
                    // share a line with double-brace echoes).
                    $chipDataAttr = $isScope
                        ? 'data-scope="' . e($key) . '"'
                        : 'data-status="' . e($key) . '"';
                @endphp
                <button type="button"
                        class="btn btn-sm status-chip {{ $isActive ? 'active' : '' }}"
                        {!! $chipDataAttr !!}>
                    <i class="fas {{ $chip['icon'] }} me-1"></i>
                    <span class="chip-label">{{ $chip['label'] }}</span>
                    <span class="chip-count badge bg-secondary ms-1">0</span>
                </button>
            @endforeach
            <input type="hidden" id="status_chip" name="status_chip" value="{{ $filters['status_chip'] }}">
            <input type="hidden" id="scope" name="scope" value="{{ $scope ?? '' }}">
        </div>
    </x-erp.left-accent-card>

    {{-- Phase 2 (UI/UX): Active filter bar — shows every active filter as a
        removable tag + "Clear all". Populated by renderActiveFilterBar(). --}}
    <x-erp.active-filter-bar id="activeFilterBar" />

    {{-- R21: Invoices table (server-side DataTables) --}}
    <x-erp.left-accent-card accent="cyan" icon="file-text" title="Invoices" title-bn="চালান তালিকা" body-class="!p-0">
        <x-slot:actions>
            {{-- Phase 5 (UI/UX): keyboard-shortcut hint. Shown only when
                the shortcut layer is enabled (desktop, fine pointer).
                Clicking it reveals a SweetAlert2 cheatsheet. --}}
            <span id="kbdHint" class="rc-kbd-hint" role="button" tabindex="0"
                  title="Keyboard shortcuts: j/k move, r receive, c call-it-a-day, e edit, / search, Esc clear"
                  aria-label="Show keyboard shortcuts">
                <kbd>j</kbd><kbd>k</kbd> navigate · <kbd>/</kbd> search
            </span>
        </x-slot:actions>
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
                {{-- Phase 5 (UI/UX): Screen-reader status region — announced on
                    filter / selection / payment changes via announceSR(). --}}
                <x-erp.sr-status id="srStatus" />
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

            {{-- Phase 4 (UI/UX): Empty states. Shown by updateEmptyState()
                in the DataTables drawCallback when recordsDisplay === 0.
                Two variants: "filters returned nothing" (with Clear-all
                button) and "genuinely no invoices" (with Create-invoice
                CTA). role="status" so screen readers announce the change. --}}
            <div id="invoiceEmptyStateFiltered" class="hidden" role="status">
                <x-erp.empty-state icon="inbox"
                    title="No invoices match your filters"
                    title-bn="কোনো চালান পাওয়া যায়নি"
                    message="Try widening the date range, switching the status chip, or clearing the search."
                    message-bn="তারিখের সীমা বাড়ান, স্ট্যাটাস পরিবর্তন করুন বা অনুসন্ধান মুছুন।">
                    <x-slot:action>
                        <button type="button" id="emptyStateClearBtn"
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 transition-colors">
                            <x-erp.icon name="rotate-ccw" class="size-4" /> Clear all filters
                        </button>
                    </x-slot:action>
                </x-erp.empty-state>
            </div>
            <div id="invoiceEmptyStateFresh" class="hidden" role="status">
                <x-erp.empty-state icon="check-circle"
                    title="You're all caught up!"
                    title-bn="সব সম্পন্ন!"
                    message="No invoices here yet — create your first invoice to get started."
                    message-bn="এখনো কোনো চালান নেই — শুরু করতে প্রথম চালান তৈরি করুন।">
                    <x-slot:action>
                        <a href="{{ route('admin.sales.cart') }}"
                           class="inline-flex items-center gap-2 rounded-lg bg-amber-500 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-600 transition-colors">
                            <x-erp.icon name="plus" class="size-4" /> Create your first invoice
                        </a>
                    </x-slot:action>
                </x-erp.empty-state>
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

    // Phase 4 (UI/UX): Branch color map emitted from config/branches.php
    // so the DataTable branch cell can render a colored pill client-side
    // (Tailwind can't generate arbitrary branch colors at build time, so
    // we use inline styles — same approach the plan specifies).
    var BRANCH_COLORS = {
        @foreach (config('branches.colors', []) as $code => $cfg)
        '{{ $code }}': { hex: '{{ $cfg['color_hex'] }}', name: {{ \Illuminate\Support\Js::from($cfg['name']) }} },
        @endforeach
    };
    var $bulkBar = $('#invoiceBulkBar');
    var $bulkCount = $('#bulkSelectedCount');
    var $selectAll = $('#selectAllInvoices');
    // Phase 4 (UI/UX): cached selectors for empty-state + live counter
    // (declared here, before the DataTable init, so they're defined by
    // the time drawCallback first fires).
    var $emptyFiltered = $('#invoiceEmptyStateFiltered');
    var $emptyFresh    = $('#invoiceEmptyStateFresh');
    var $tableWrap     = $('.sales-invoices-desktop-table');
    var $cardsWrap     = $('#invoiceCards');
    var $heroCount     = $('#heroInvoiceCount');

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

    // ============================================================
    // ====== Phase 2 (UI/UX): Date presets + active filter bar ====
    // ============================================================
    // Three behaviours restored from Legacy sales-today-index.js:
    //  1. One-click date presets (Today / Yesterday / Last 7 days /
    //     This month / Custom) — resolves to concrete from_date /
    //     to_date values on the client, no extra round-trip.
    //  2. localStorage persistence — filters survive page reload
    //     (key rcerp_sales_invoices_filters_v1). Skipped when the URL
    //     already carries explicit filter params (forceUrlParams).
    //  3. Active filter bar — a removable tag per active filter +
    //     "Clear all", rendered live from currentFilterParams().

    var FILTERS_KEY = 'rcerp_sales_invoices_filters_v1';
    var $presets    = $('#datePresets');
    var $activeBar  = $('#activeFilterBar');
    var $activeTags = $('#activeFilterTags');

    // Preset pill class sets (must match <x-erp.date-presets> markup).
    var PRESET_INACTIVE = 'bg-white border-amber-200 text-amber-700 hover:bg-amber-50';
    var PRESET_ACTIVE   = 'bg-gradient-to-r from-amber-500 to-orange-500 border-transparent text-white shadow-sm';

    // Local YYYY-MM-DD formatter (avoids toISOString() UTC off-by-one).
    function ymd(d) {
        var m = '' + (d.getMonth() + 1);
        var da = '' + d.getDate();
        return d.getFullYear() + '-' + (m.length < 2 ? '0' + m : m) + '-' + (da.length < 2 ? '0' + da : da);
    }

    // Resolve the current from/to dates to a preset key ('' = no match / custom).
    function detectPresetFromDates() {
        var from = $('#from_date').val();
        var to   = $('#to_date').val();
        if (!from || !to) return '';
        var now   = new Date();
        var t     = ymd(now);
        var y     = new Date(now); y.setDate(y.getDate() - 1);
        var s7    = new Date(now); s7.setDate(s7.getDate() - 6);
        var first = new Date(now.getFullYear(), now.getMonth(), 1);
        if (from === t && to === t) return 'today';
        if (from === ymd(y) && to === ymd(y)) return 'yesterday';
        if (from === ymd(s7) && to === t) return 'last_7_days';
        if (from === ymd(first) && to === t) return 'this_month';
        return '';
    }

    // Toggle the visual active state of the preset pills. "custom" is
    // never active — clicking it only clears the highlight + focuses the
    // date inputs.
    function setActivePreset(key) {
        if (!$presets.length) return;
        $presets.find('.date-preset-btn').each(function () {
            var k = $(this).data('preset');
            var isActive = (k === key && k !== 'custom');
            $(this).attr('aria-pressed', isActive ? 'true' : 'false');
            if (isActive) {
                $(this).removeClass(PRESET_INACTIVE).addClass(PRESET_ACTIVE);
            } else {
                $(this).removeClass(PRESET_ACTIVE).addClass(PRESET_INACTIVE);
            }
        });
    }

    // Re-evaluate which preset (if any) matches the current dates.
    // When a workflow scope chip is active, no preset is highlighted
    // (the scope owns the date context).
    function refreshPresetHighlight() {
        if (currentFilterParams().scope) { setActivePreset(''); return; }
        setActivePreset(detectPresetFromDates());
    }

    // Apply a date preset → set from/to, clear scope so dates take
    // precedence, reload the table.
    function applyDatePreset(key) {
        var now   = new Date();
        var t     = ymd(now);
        var y     = new Date(now); y.setDate(y.getDate() - 1);
        var s7    = new Date(now); s7.setDate(s7.getDate() - 6);
        var first = new Date(now.getFullYear(), now.getMonth(), 1);

        if (key === 'today')            { $('#from_date').val(t);        $('#to_date').val(t); }
        else if (key === 'yesterday')   { $('#from_date').val(ymd(y));   $('#to_date').val(ymd(y)); }
        else if (key === 'last_7_days') { $('#from_date').val(ymd(s7));  $('#to_date').val(t); }
        else if (key === 'this_month')  { $('#from_date').val(ymd(first)); $('#to_date').val(t); }
        // 'custom' → leave the date inputs untouched so the user can pick.

        if (key !== 'custom') {
            // A concrete preset overrides any workflow scope chip so the
            // explicit date range is the active filter context.
            $scopeInput.val('');
            $chipInput.val('all');
            $('.status-chip').removeClass('active');
            $('.status-chip[data-status="all"]').addClass('active');
            setActivePreset(key);
        } else {
            setActivePreset('');
        }

        dt.ajax.reload();
        scheduleSummary();
        updateExportLink();
        saveFilters();
        renderActiveFilterBar();
        announceSR(key === 'custom' ? 'Custom date range' : ('Date filter: ' + key.replace(/_/g, ' ')));

        if (key === 'custom') { $('#from_date').focus(); }
    }

    // Build a single filter-tag HTML string (mirrors <x-erp.filter-tag>).
    function filterTagHTML(key, label) {
        return '<span class="inline-flex items-center gap-1.5 rounded-full bg-white border border-amber-300 px-2.5 py-0.5 text-xs text-amber-900">'
             + '<span>' + escapeHtml(label) + '</span>'
             + '<button type="button" data-clear-filter="' + escapeHtml(key) + '"'
             + ' aria-label="Remove ' + escapeHtml(label) + ' filter"'
             + ' class="size-4 rounded-full hover:bg-amber-100 inline-flex items-center justify-center text-amber-600 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400">'
             + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>'
             + '</button></span>';
    }

    // Read currentFilterParams() and render one tag per active filter.
    // The bar hides itself when nothing is active.
    function renderActiveFilterBar() {
        if (!$activeBar.length) return;
        var p = currentFilterParams();
        var tags = [];

        // Scope chip (today / pending_godown / pending_challan) takes
        // precedence over status_chip for the "what am I filtering" label.
        var scopeLabels = { today: 'Today', pending_godown: 'Pending Godown', pending_challan: 'Pending Challan' };
        var statusLabels = { draft: 'Draft', confirmed: 'Confirmed', cancelled: 'Cancelled', reversed: 'Reversed', awaiting_payment: 'Awaiting Payment' };

        if (p.scope && scopeLabels[p.scope]) {
            tags.push({ key: 'scope', label: 'Scope: ' + scopeLabels[p.scope] });
        } else if (p.status_chip && p.status_chip !== 'all' && statusLabels[p.status_chip]) {
            tags.push({ key: 'status_chip', label: 'Status: ' + statusLabels[p.status_chip] });
        }
        if (p.from_date) tags.push({ key: 'from_date', label: 'From: ' + p.from_date });
        if (p.to_date)   tags.push({ key: 'to_date',   label: 'To: ' + p.to_date });
        if (p.customer_id) {
            var $c = $('#customer_id option[value="' + p.customer_id + '"]');
            tags.push({ key: 'customer_id', label: 'Customer: ' + ($c.length ? $.trim($c.text()) : p.customer_id) });
        }
        if (p.branch_id) {
            var $b = $('#branch_id option[value="' + p.branch_id + '"]');
            tags.push({ key: 'branch_id', label: 'Branch: ' + ($b.length ? $.trim($b.text()) : p.branch_id) });
        }
        if (p.search)     tags.push({ key: 'search',     label: 'Search: ' + p.search });
        if (p.smart_sort === '1') tags.push({ key: 'smart_sort', label: 'Smart sort' });

        $activeTags.empty();
        if (tags.length === 0) {
            $activeBar.addClass('hidden');
            return;
        }
        $activeBar.removeClass('hidden');
        var html = '';
        for (var i = 0; i < tags.length; i++) {
            html += filterTagHTML(tags[i].key, tags[i].label);
        }
        $activeTags.html(html);
    }

    // Debounced save of the current filter state to localStorage.
    var saveDebounce = null;
    function saveFilters() {
        clearTimeout(saveDebounce);
        saveDebounce = setTimeout(function () {
            try { localStorage.setItem(FILTERS_KEY, JSON.stringify(currentFilterParams())); } catch (e) {}
        }, 400);
    }

    // Hydrate the filter form from localStorage on first load. Skipped
    // when the URL already carries explicit filter params (forceUrlParams)
    // — in that case the server-rendered values win.
    function loadFilters() {
        try {
            var url = new URL(window.location.href);
            var hasUrlParams = url.searchParams.has('from_date') || url.searchParams.has('to_date')
                || url.searchParams.has('customer_id') || url.searchParams.has('branch_id')
                || url.searchParams.has('status') || url.searchParams.has('scope');
            if (hasUrlParams) return;

            var raw = localStorage.getItem(FILTERS_KEY);
            if (!raw) return;
            var p = JSON.parse(raw);
            if (!p || typeof p !== 'object') return;

            if (p.from_date)   $('#from_date').val(p.from_date);
            if (p.to_date)     $('#to_date').val(p.to_date);
            if (p.customer_id) { $('#customer_id').val(p.customer_id).trigger('change'); }
            if (p.branch_id)   { $('#branch_id').val(p.branch_id).trigger('change'); }
            if (p.search)      $search.val(p.search);
            $('#filterSmartSort').prop('checked', p.smart_sort !== '0');
            if (p.status_chip) $chipInput.val(p.status_chip);
            if (p.scope)       $scopeInput.val(p.scope);

            // Re-sync the status/scope chip active styling to match.
            $('.status-chip').removeClass('active');
            if (p.scope) {
                $('.status-chip[data-scope="' + p.scope + '"]').addClass('active');
            } else if (p.status_chip) {
                $('.status-chip[data-status="' + p.status_chip + '"]').addClass('active');
            } else {
                $('.status-chip[data-status="all"]').addClass('active');
            }
        } catch (e) {}
    }

    // Wipe every filter back to defaults + clear localStorage + redraw.
    function clearAllFilters() {
        $('#from_date, #to_date').val('');
        $('#customer_id, #branch_id').val('').trigger('change');
        $search.val('');
        $('#filterSmartSort').prop('checked', true);   // smart sort on by default
        $scopeInput.val('');
        $chipInput.val('all');
        $('.status-chip').removeClass('active');
        $('.status-chip[data-status="all"]').addClass('active');
        setActivePreset('');
        try { localStorage.removeItem(FILTERS_KEY); } catch (e) {}
        dt.ajax.reload();
        scheduleSummary();
        updateExportLink();
        renderActiveFilterBar();
        announceSR('All filters cleared');
    }

    // Remove a single filter (× on a tag) and redraw.
    function clearSingleFilter(key) {
        if (key === 'scope') {
            $scopeInput.val('');
            $chipInput.val('all');
            $('.status-chip').removeClass('active');
            $('.status-chip[data-status="all"]').addClass('active');
        } else if (key === 'status_chip') {
            $chipInput.val('all');
            $('.status-chip').removeClass('active');
            $('.status-chip[data-status="all"]').addClass('active');
        } else if (key === 'from_date') {
            $('#from_date').val('');
        } else if (key === 'to_date') {
            $('#to_date').val('');
        } else if (key === 'customer_id') {
            $('#customer_id').val('').trigger('change');
        } else if (key === 'branch_id') {
            $('#branch_id').val('').trigger('change');
        } else if (key === 'search') {
            $search.val('');
        } else if (key === 'smart_sort') {
            $('#filterSmartSort').prop('checked', false);
        }
        refreshPresetHighlight();
        dt.ajax.reload();
        scheduleSummary();
        updateExportLink();
        saveFilters();
        renderActiveFilterBar();
        announceSR('Filter removed');
    }

    // Hydrate from localStorage BEFORE the first DataTable AJAX so the
    // initial draw already reflects the saved filter state.
    loadFilters();

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
        // Phase 4 (UI/UX): wrap only the table (`t`) in .rc-table-scroll
        // so the length-menu / pagination / info stay OUTSIDE the sticky
        // scroll container (otherwise they'd scroll away with the rows).
        dom: '<"row mb-2"<"col-md-6"l><"col-md-6 text-end"p>>r<"rc-table-scroll"t><"row mt-2"<"col-md-6"i><"col-md-6 text-end"p>>',
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
                // Phase 4 (UI/UX): Branch cell — colored pill tinted by
                // branch code (config/branches.php). On mobile the name
                // is hidden via CSS so only the code shows, saving space.
                data: 'branch_name',
                render: function (data, type, row) {
                    if (type !== 'display') return data || '';
                    return branchPillHtml(row.branch_code, data);
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
                // Phase 4 (UI/UX): Due column highlight — red pill for
                // outstanding dues, green ✓ Paid pill for settled invoices.
                // Meaning is conveyed by text (৳amount / ✓ Paid), not color
                // alone; aria-labels describe the state for screen readers.
                data: 'due_amount',
                className: 'text-end',
                render: function (data, type, row) {
                    if (type !== 'display') return data || 0;
                    var due = parseFloat(data || 0);
                    if (due > 0.01) {
                        return '<span class="rc-due-pill rc-due-outstanding" '
                             +      'aria-label="Due ৳' + numberFormat(due, 2) + '">'
                             +   '<i class="fas fa-circle-exclamation"></i> ৳'
                             +   numberFormat(due, 2)
                             + '</span>';
                    }
                    return '<span class="rc-due-pill rc-due-paid" '
                         +      'aria-label="Fully paid">'
                         +   '<i class="fas fa-check"></i> Paid'
                         + '</span>';
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
                // Phase 3 (UI/UX): per-row actions — action-group pattern.
                // ≤3 inline icon buttons (View + Edit + Receive) + an
                // overflow ⋯ dropdown for the rest (Call-it-a-day / Print
                // / Cancel). Mirrors the <x-erp.action-group> Blade
                // component markup. Uses .rc-action-btn (defined in the
                // <style> block below) for compact icon buttons matching
                // the <x-erp.action-button> spec. aria-label on every
                // button includes the invoice code for screen readers.
                data: null,
                orderable: false,
                className: 'text-center text-nowrap',
                render: function (data, type, row) {
                    if (type !== 'display') return '';
                    var code = escapeHtml(row.invoice_code || '');
                    var html = '<div class="d-inline-flex gap-1 justify-content-center align-items-center">';

                    // --- Inline (max 3): View + Edit + Receive ---

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

                    // --- Overflow ⋯: Call-it-a-day / Print / Cancel ---
                    // Only render the dropdown if at least one overflow
                    // action is available.
                    var overflowItems = '';

                    // Call it a day (paid, not yet called)
                    if (row.show_call_a_day) {
                        overflowItems += '<li><button type="button" class="dropdown-item btn-call-it-a-day" '
                                      +  'data-invoice-id="' + row.id + '" data-invoice-code="' + code + '" '
                                      +  'aria-label="Call it a day for invoice ' + code + '">'
                                      +  '<i class="fas fa-check-circle text-orange-500 me-2"></i>Call it a day</button></li>';
                    }

                    // Print invoice (confirmed + not reversed)
                    if (row.show_print && row.print_invoice_url) {
                        overflowItems += '<li><a href="' + row.print_invoice_url + '" target="_blank" rel="noopener" '
                                      +  'class="dropdown-item" aria-label="Print invoice ' + code + '">'
                                      +  '<i class="fas fa-print text-gray-500 me-2"></i>Print invoice</a></li>';
                    }

                    // Cancel (draft only) — destructive, shown last + red.
                    if (row.show_cancel) {
                        overflowItems += '<li><hr class="dropdown-divider my-1"></li>'
                                      +  '<li><button type="button" class="dropdown-item text-danger btn-cancel-invoice" '
                                      +  'data-invoice-id="' + row.id + '" data-invoice-code="' + code + '" '
                                      +  'aria-label="Cancel invoice ' + code + '">'
                                      +  '<i class="fas fa-ban me-2"></i>Cancel invoice</button></li>';
                    }

                    if (overflowItems) {
                        html += '<div class="dropdown d-inline-block">'
                             +  '<button type="button" class="rc-action-btn" data-bs-toggle="dropdown" '
                             +  'aria-expanded="false" title="More" '
                             +  'aria-label="More actions for invoice ' + code + '">'
                             +  '<i class="fas fa-ellipsis-h"></i></button>'
                             +  '<ul class="dropdown-menu dropdown-menu-end shadow-lg rounded-md border border-gray-200 bg-white py-1" style="min-width:12rem;">'
                             +  overflowItems
                             +  '</ul></div>';
                    }

                    html += '</div>';
                    return html;
                },
            },
        ],
        drawCallback: function () {
            var api = this.api();

            // R23: Render mobile cards from the current page's data.
            renderMobileCards(api);

            // Phase 4 (UI/UX): Empty state + live hero counter. The empty
            // state replaces the table when recordsDisplay === 0; the hero
            // counter reflects the filtered count so it updates live as
            // the user changes filters / calls-it-a-day.
            updateEmptyState(api);
            updateHeroCount(api);

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
        // Phase 2 (UI/UX): persist + refresh active bar + preset highlight.
        saveFilters();
        renderActiveFilterBar();
        refreshPresetHighlight();
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
        // Phase 2 (UI/UX): persist + refresh active bar + preset highlight.
        saveFilters();
        renderActiveFilterBar();
        refreshPresetHighlight();
    });

    // Phase 2 (UI/UX): the form Clear button now delegates to the
    // unified clearAllFilters() (also used by the active-filter-bar's
    // "Clear all") so localStorage is wiped + the active bar re-renders.
    $('#clearFiltersBtn').on('click', function () {
        clearAllFilters();
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
            // Phase 2 (UI/UX): persist + refresh active bar.
            saveFilters();
            renderActiveFilterBar();
        }, 320);
    });

    // Date / customer / branch / smart-sort changes trigger an
    // immediate reload (these are discrete picks, not free text).
    $('#from_date, #to_date, #customer_id, #branch_id, #filterSmartSort').on('change', function () {
        dt.ajax.reload();
        scheduleSummary();
        updateExportLink();
        // Phase 2 (UI/UX): persist + refresh active bar + preset highlight
        // (manual date edits may match/undo a preset → re-evaluate).
        saveFilters();
        renderActiveFilterBar();
        refreshPresetHighlight();
    });

    // ============================================================
    // ====== Phase 2 (UI/UX): preset + tag + clear-all handlers ===
    // ============================================================
    // Delegated so they survive any future DOM redraws of the filter
    // region (the preset bar + active bar are static, but delegation is
    // the safe pattern used elsewhere on this page).

    // Date preset pill click → resolve to from/to + reload.
    $presets.on('click', '.date-preset-btn', function () {
        applyDatePreset($(this).data('preset'));
    });

    // Active-filter-tag × button → remove that single filter.
    $(document).on('click', '[data-clear-filter]', function () {
        clearSingleFilter($(this).data('clear-filter'));
    });

    // Active-filter-bar "Clear all" → wipe everything.
    $('#clearAllFilters').on('click', function () {
        clearAllFilters();
    });

    // Initial render: paint the active bar + preset highlight to match
    // the (possibly localStorage-hydrated) starting state.
    renderActiveFilterBar();
    refreshPresetHighlight();

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
    // ====== Phase 4 (UI/UX): Empty state + live hero counter =====
    // ============================================================
    // updateEmptyState(): when the DataTable has 0 visible rows, hide
    // the table + mobile cards and show one of two friendly empty-state
    // variants. recordsTotal === 0 → "genuinely empty" (CTA: Create
    // invoice); recordsTotal > 0 → "filters hid everything" (CTA:
    // Clear all filters). role="status" on the wrapper lets screen
    // readers announce the change. (Selectors $emptyFiltered /
    // $emptyFresh / $tableWrap / $cardsWrap / $heroCount are declared
    // earlier, before the DataTable init.)
    function updateEmptyState(api) {
        var info = api.page.info();
        var shown = info.recordsDisplay;
        if (shown > 0) {
            $emptyFiltered.addClass('hidden');
            $emptyFresh.addClass('hidden');
            $tableWrap.removeClass('hidden');
            return;
        }
        // No rows to show — hide the table, show the right empty state.
        $tableWrap.addClass('hidden');
        $cardsWrap.empty();
        if (info.recordsTotal === 0) {
            $emptyFresh.removeClass('hidden');
            $emptyFiltered.addClass('hidden');
        } else {
            $emptyFiltered.removeClass('hidden');
            $emptyFresh.addClass('hidden');
        }
    }

    // updateHeroCount(): reflect the filtered record count in the hero
    // subtitle so it updates live as filters / call-it-a-day change.
    function updateHeroCount(api) {
        if (!$heroCount.length) return;
        var n = api.page.info().recordsDisplay;
        $heroCount.text(numberFormat(n));
    }

    // Empty-state "Clear all filters" button → reuse the Phase 2
    // clearAllFilters() (same handler as the active-filter-bar button).
    $(document).on('click', '#emptyStateClearBtn', function () {
        clearAllFilters();
    });

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
            // Phase 4 (UI/UX): empty state is now handled centrally by
            // updateEmptyState() (shared desktop + mobile), so just clear
            // the cards container here.
            $cards.empty();
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
            // Phase 3 (UI/UX): mobile action-group — View + Receive inline
            // + overflow ⋯ (Edit / Call-it-a-day / Print / Cancel).
            html +=     '<a href="' + row.show_url + '" class="btn btn-sm btn-outline-secondary">' +
                            '<i class="fas fa-eye"></i> View</a>';
            if (row.show_receive) {
                html +=   '<button type="button" class="btn btn-sm btn-success btn-receive-payment" ' +
                          'data-invoice-id="' + row.id + '" ' +
                          'data-invoice-code="' + escapeHtml(row.invoice_code) + '">' +
                          '<i class="fas fa-hand-holding-dollar me-1"></i>Receive</button>';
            }

            // Overflow ⋯ — only render if ≥1 secondary action exists.
            var mOver = '';
            if (row.show_edit && row.edit_url) {
                mOver += '<li><a href="' + row.edit_url + '" class="dropdown-item" ' +
                         'aria-label="Edit invoice ' + escapeHtml(row.invoice_code) + '">' +
                         '<i class="fas fa-pen text-amber-600 me-2"></i>Edit draft</a></li>';
            }
            if (row.show_call_a_day) {
                mOver += '<li><button type="button" class="dropdown-item btn-call-it-a-day" ' +
                         'data-invoice-id="' + row.id + '" ' +
                         'data-invoice-code="' + escapeHtml(row.invoice_code) + '" ' +
                         'aria-label="Call it a day for invoice ' + escapeHtml(row.invoice_code) + '">' +
                         '<i class="fas fa-check-circle text-orange-500 me-2"></i>Call it a day</button></li>';
            }
            if (row.show_print && row.print_invoice_url) {
                mOver += '<li><a href="' + row.print_invoice_url + '" target="_blank" rel="noopener" ' +
                         'class="dropdown-item" aria-label="Print invoice ' + escapeHtml(row.invoice_code) + '">' +
                         '<i class="fas fa-print text-gray-500 me-2"></i>Print invoice</a></li>';
            }
            if (row.show_cancel) {
                mOver += '<li><hr class="dropdown-divider my-1"></li>' +
                         '<li><button type="button" class="dropdown-item text-danger btn-cancel-invoice" ' +
                         'data-invoice-id="' + row.id + '" ' +
                         'data-invoice-code="' + escapeHtml(row.invoice_code) + '" ' +
                         'aria-label="Cancel invoice ' + escapeHtml(row.invoice_code) + '">' +
                         '<i class="fas fa-ban me-2"></i>Cancel invoice</button></li>';
            }
            if (mOver) {
                html += '<div class="dropdown d-inline-block">' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" ' +
                        'aria-expanded="false" aria-label="More actions for invoice ' + escapeHtml(row.invoice_code) + '">' +
                        '<i class="fas fa-ellipsis-h"></i></button>' +
                        '<ul class="dropdown-menu dropdown-menu-end shadow-lg rounded-md border border-gray-200 bg-white py-1" style="min-width:12rem;">' +
                        mOver + '</ul></div>';
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

    // Phase 5 (UI/UX): Modal focus management — remember the button that
    // opened the modal so focus can be restored to it on close. Bootstrap
    // traps focus inside the modal while open by default; we additionally
    // move focus to the amount input once the body loads.
    var $receiveTriggerBtn = null;

    $(document).on('click', '.btn-receive-payment', function () {
        var invoiceId = $(this).data('invoice-id');
        if (!invoiceId) return;
        $receiveTriggerBtn = $(this);   // remember for restore-on-close
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
            // Phase 5 (UI/UX): move focus to the amount input once the
            // form is injected — keyboard users can start typing immediately.
            var $amt = $('#srpAmount');
            if ($amt.length) {
                $amt.focus();
            } else {
                // No amount field (e.g. error state) — focus the first
                // focusable control in the modal.
                $modalContent.find('button, a, input, select, textarea')
                    .filter(':visible').first().focus();
            }
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

    // Phase 5 (UI/UX): restore focus to the triggering "Receive" button
    // when the modal closes (WCAG 2.4.3 — focus order). Clears the
    // remembered trigger afterwards so a later programmatic open doesn't
    // steal focus from the wrong element.
    $receiveModal.on('hidden.bs.modal', function () {
        if ($receiveTriggerBtn && $receiveTriggerBtn.length) {
            $receiveTriggerBtn.focus();
            $receiveTriggerBtn = null;
        }
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
    // ====== Phase 3 (UI/UX): Inline payment reversal =============
    // ============================================================
    // Delegated handler — survives DataTables redraws + modal re-fetches.
    // The .btn-reverse-payment buttons live inside the receive modal's
    // "Payments on this invoice" list (rendered server-side by
    // _receive_modal_body.blade.php, role-gated to accountant/manager/
    // admin/superadmin). Clicking one opens a SweetAlert2 reason prompt
    // (textarea, min 5 chars) → AJAX POST to admin.customer-payments.cancel
    // → on success the modal body is re-fetched (the reversed payment's
    // allocation is deleted server-side, so it vanishes from the list +
    // the balance due goes back up) + the DataTable reloads to reflect
    // the new due/paid columns. Mirrors the Legacy reverse_payment flow
    // without any page navigation.
    $(document).on('click', '.btn-reverse-payment', function () {
        var pid  = parseInt($(this).data('payment-id'), 10) || 0;
        var code = String($(this).data('payment-code') || '');
        if (!pid) return;

        Swal.fire({
            title: 'Reverse payment ' + escapeHtml(code) + '?',
            html: 'This reverses the GL entry, customer ledger, and invoice allocation. '
                + 'The invoice\'s due amount will go back up. <b>This cannot be undone.</b>',
            icon: 'warning',
            input: 'textarea',
            inputPlaceholder: 'Reason for reversal (required, min 5 chars)…',
            inputAttributes: { 'aria-label': 'Reason for reversal (minimum 5 characters)' },
            inputValidator: function (v) {
                if (!v || String(v).trim().length < 5) {
                    return 'Reason must be at least 5 characters.';
                }
                return null;
            },
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-rotate-left me-1"></i>Yes, reverse it',
            cancelButtonText: 'Keep it',
            confirmButtonColor: '#dc2626', // red-600
        }).then(function (r) {
            if (!r.isConfirmed) return;
            // Cancel URL built inline (matches the cancel-invoice pattern)
            // — avoids route() URL-encoding a placeholder.
            var url = '/admin/customer-payments/' + pid + '/cancel';
            $.ajax({
                url: url,
                method: 'POST',
                data: { _token: ROUTES.csrf, cancel_reason: r.value },
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }).done(function (data) {
                var payCode = (data && data.payment_code) || code;
                Swal.fire({
                    toast: true, position: 'top-end', icon: 'success',
                    title: 'Payment ' + escapeHtml(payCode) + ' reversed.',
                    showConfirmButton: false, timer: 2400,
                });
                announceSR('Payment ' + payCode + ' reversed.');

                // Re-fetch the modal body so the reversed payment
                // disappears + the balance/summary stats update. The
                // invoiceId is read from the modal body's data attr.
                var $body = $modalContent.find('.receive-modal-body');
                var invoiceId = $body.length ? ($body.data('invoice-id') || 0) : 0;
                if (invoiceId) {
                    $.ajax({
                        url: '/admin/sales-invoices/' + invoiceId + '/receive-modal',
                        method: 'GET',
                        dataType: 'html',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    }).done(function (html) {
                        $modalContent.html(html);
                        initReceiveModalBody();
                    });
                }

                // Refresh the table so due/paid/status columns update.
                dt.ajax.reload();
                scheduleSummary();

                // Fire a custom event so any future listener can react
                // (e.g. auto-collapsing the row). Mirrors the spec's
                // salesToday:paymentRecorded with { reversedPaymentId }.
                $(document).trigger('salesToday:paymentRecorded', [{
                    reversedPaymentId: pid,
                    paymentCode: payCode,
                }]);
            }).fail(function (xhr) {
                var msg;
                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    var errs = xhr.responseJSON.errors;
                    msg = Object.keys(errs).map(function (k) { return errs[k].join(' '); }).join(' ');
                } else {
                    msg = (xhr.responseJSON && xhr.responseJSON.message)
                        || xhr.statusText
                        || 'Server error';
                }
                Swal.fire({
                    icon: 'error',
                    title: 'Could not reverse payment',
                    html: escapeHtml(msg),
                });
            });
        });
    });

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
    // Phase 4 (UI/UX): colored branch pill for the DataTable branch cell.
    // Tinted with the branch's config color (config/branches.php) via
    // inline style — bg = hex+15 (8% alpha), text = hex, border = hex+33.
    // Mirrors the <x-erp.branch-pill> Blade component visually.
    function branchPillHtml(code, name) {
        code = String(code || '').toUpperCase();
        name = name || '';
        if (!code) return '<span class="text-muted">—</span>';
        var c = BRANCH_COLORS[code] || { hex: '#64748b', name: name };
        var label = name || c.name || code;
        return '<span class="rc-branch-pill" '
             +      'style="background:' + c.hex + '15;color:' + c.hex + ';border-color:' + c.hex + '33;" '
             +      'aria-label="Branch: ' + escapeHtml(label) + ' (' + code + ')">'
             +   '<i class="fas fa-code-branch"></i>'
             +   '<span class="rc-branch-name">' + escapeHtml(label) + '</span>'
             +   '<span class="rc-branch-code">' + escapeHtml(code) + '</span>'
             + '</span>';
    }

    // ============================================================
    // ====== Phase 5 (UI/UX): Keyboard shortcuts ==================
    // ============================================================
    // Power-user navigation. Desktop only — the whole layer is skipped
    // when the primary pointer is coarse (touch) so mobile users never
    // get accidental triggers. Keys:
    //   j / k   — move row focus down / up
    //   r       — receive payment on the focused row
    //   c       — call it a day on the focused row
    //   e       — edit the focused row (draft only)
    //   /       — focus the smart-search input
    //   Esc     — clear row focus / close any open dropdown
    // Any handler bails out when focus is in a form field, a contenteditable,
    // or inside the receive-payment modal (so users can type freely).
    var SHORTCUTS_ENABLED = (function () {
        if (!window.matchMedia) return false;
        // Skip on touch-primary devices.
        if (window.matchMedia('(pointer: coarse)').matches) return false;
        // Skip if the user has no physical keyboard hint (very small screens).
        if (window.matchMedia('(max-width: 767.98px)').matches) return false;
        return true;
    })();

    var $focusedRow = null;

    function isTypingTarget(el) {
        if (!el) return false;
        var tag = (el.tagName || '').toUpperCase();
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
            || el.isContentEditable === true;
    }

    function inReceiveModal() {
        return $receiveModal.hasClass('show');
    }

    function visibleRows() {
        return $('#invoiceTable tbody tr').filter(function () {
            return $(this).is(':visible');
        });
    }

    function clearRowFocus() {
        if ($focusedRow) {
            $focusedRow.removeClass('rc-row-focused').attr('tabindex', '-1');
            $focusedRow = null;
        }
    }

    function focusRow($tr) {
        clearRowFocus();
        if (!$tr || !$tr.length) return;
        $focusedRow = $tr;
        $tr.addClass('rc-row-focused').attr('tabindex', '0');
        // Scroll the row into view within the sticky scroll container.
        $tr[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        $tr[0].focus({ preventScroll: true });
        var code = $tr.find('a.fw-semibold').first().text() || 'row';
        announceSR('Row ' + code + ' focused. Press R to receive, C to call it a day, E to edit.');
    }

    function moveRowFocus(delta) {
        var $rows = visibleRows();
        if (!$rows.length) { announceSR('No rows to navigate'); return; }
        var idx = $focusedRow ? $rows.index($focusedRow) : -1;
        var next = Math.max(0, Math.min($rows.length - 1, idx + delta));
        focusRow($rows.eq(next));
    }

    function triggerRowAction(selector, actionLabel) {
        if (!$focusedRow || !$focusedRow.length) {
            announceSR('No row focused. Use J or K to select a row first.');
            return;
        }
        var $btn = $focusedRow.find(selector).first();
        if (!$btn.length) {
            announceSR(actionLabel + ' is not available for this row.');
            return;
        }
        announceSR(actionLabel);
        $btn.trigger('click');
    }

    if (SHORTCUTS_ENABLED) {
        $(document).on('keydown', function (e) {
            // Never intercept modifier combos (Ctrl/Cmd/Alt) — those belong
            // to the browser/OS.
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            // Don't interfere while typing in any field.
            if (isTypingTarget(e.target)) {
                // Esc still works to blur the field.
                if (e.key === 'Escape') { e.target.blur(); }
                return;
            }
            // Don't interfere while the receive modal is open (typing $ amounts).
            if (inReceiveModal()) return;

            switch (e.key) {
                case 'j':
                    e.preventDefault();
                    moveRowFocus(1);
                    break;
                case 'k':
                    e.preventDefault();
                    moveRowFocus(-1);
                    break;
                case 'r':
                    e.preventDefault();
                    triggerRowAction('.btn-receive-payment', 'Receive payment');
                    break;
                case 'c':
                    e.preventDefault();
                    triggerRowAction('.btn-call-it-a-day', 'Call it a day');
                    break;
                case 'e':
                    e.preventDefault();
                    triggerRowAction('.rc-action-edit', 'Edit invoice');
                    break;
                case '/':
                    e.preventDefault();
                    var $s = $('#filterSearch');
                    if ($s.length) { $s.focus(); announceSR('Search focused'); }
                    break;
                case 'Escape':
                    clearRowFocus();
                    // Close any stray open dropdown.
                    $('.dropdown-menu.show').removeClass('show');
                    break;
            }
        });

        // Re-focus the first row when the table redraws if focus was active.
        // (DataTables rebuilds <tbody> on every draw, so the old $focusedRow
        // node is gone — we just reset and let the user press j again.)
        $table.on('draw.dt', function () { clearRowFocus(); });

        // Show the keyboard-hint badge now that we know the layer is active.
        $('#kbdHint').css('display', 'inline-flex');

        // Click / Enter / Space on the hint → SweetAlert2 cheatsheet.
        $('#kbdHint').on('click keydown', function (e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            if (typeof Swal === 'undefined') return;
            Swal.fire({
                title: 'Keyboard shortcuts',
                html:
                    '<div class="text-start" style="font-size:0.9rem;line-height:1.9;">' +
                      '<div><kbd style="font-family:monospace;border:1px solid #ccc;border-radius:4px;padding:1px 6px;">j</kbd> / ' +
                      '<kbd style="font-family:monospace;border:1px solid #ccc;border-radius:4px;padding:1px 6px;">k</kbd> — move focus down / up a row</div>' +
                      '<div><kbd style="font-family:monospace;border:1px solid #ccc;border-radius:4px;padding:1px 6px;">r</kbd> — receive payment on the focused row</div>' +
                      '<div><kbd style="font-family:monospace;border:1px solid #ccc;border-radius:4px;padding:1px 6px;">c</kbd> — call it a day on the focused row</div>' +
                      '<div><kbd style="font-family:monospace;border:1px solid #ccc;border-radius:4px;padding:1px 6px;">e</kbd> — edit the focused row (draft only)</div>' +
                      '<div><kbd style="font-family:monospace;border:1px solid #ccc;border-radius:4px;padding:1px 6px;">/</kbd> — focus the smart-search box</div>' +
                      '<div><kbd style="font-family:monospace;border:1px solid #ccc;border-radius:4px;padding:1px 6px;">Esc</kbd> — clear row focus / close dropdown</div>' +
                    '</div>',
                confirmButtonText: 'Got it',
                confirmButtonColor: '#d97706',
            });
        });
    }

    // ============================================================
    // ====== Phase 6 (UI/UX): Mobile responsive tweaks ============
    // ============================================================
    // Collapse the filter card by default on mobile (<768px) to save
    // vertical space. Desktop keeps it open (the <details open> attr
    // + CSS forces the body visible on ≥768px). User can still toggle
    // it manually — this only sets the INITIAL state.
    if (window.matchMedia && window.matchMedia('(max-width: 767.98px)').matches) {
        var filterCardEl = document.getElementById('filterCard');
        if (filterCardEl) filterCardEl.open = false;
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
    .status-chip[data-status="draft"].active            { background: #b45309; }   /* Phase 5: amber-700 — was #d97706 (3.2:1, failed AA) → 5.0:1 ✓ */
    .status-chip[data-status="confirmed"].active        { background: #15803d; }   /* Phase 5: green-700 — was #16a34a (3.3:1, failed AA) → 5.1:1 ✓ */
    .status-chip[data-status="cancelled"].active        { background: #64748b; }
    .status-chip[data-status="reversed"].active         { background: #b91c1c; }
    /* BUG-52: scope chips — distinct colors so the workflow queue
       chips are visually distinguishable from the status chips. */
    .status-chip[data-scope="today"].active           { background: #4f46e5; }
    .status-chip[data-scope="pending_godown"].active  { background: #0e7490; }     /* Phase 5: cyan-700 — was #0891b2 (3.7:1, failed AA) → 5.4:1 ✓ */
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

    /* ============================================================
       Phase 4 (UI/UX): Sticky DataTable header
       .rc-table-scroll is the DataTables-generated wrapper around the
       table (see the `dom` option). It gets a max-height so the body
       scrolls independently while the thead stays pinned. Sticky is
       relative to this scroll container (not the viewport) — exactly
       what we want. Opaque amber background + blur so rows don't show
       through on scroll. The length-menu / pagination / info stay
       OUTSIDE this container so they remain visible.
       ============================================================ */
    .rc-table-scroll {
        max-height: 28rem;        /* ~ max-h-[28rem] */
        overflow-y: auto;
    }
    #invoiceTable thead th {
        position: -webkit-sticky;
        position: sticky;
        top: 0;
        z-index: 10;
        background: rgb(254 243 199 / 0.96);   /* amber-50/96 */
        backdrop-filter: blur(4px);
        box-shadow: inset 0 -1px 0 0 rgb(252 211 77); /* amber-300 bottom rule */
    }

    /* ============================================================
       Phase 4 (UI/UX): Due column highlight pills (.rc-due-pill)
       Rendered client-side by the DataTable due_amount column. Red
       pill = outstanding, green pill = fully paid. Meaning is also
       in the text (৳amount / ✓ Paid) + aria-label, not color alone.
       ============================================================ */
    .rc-due-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.125rem 0.5rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 600;
        line-height: 1.25;
        white-space: nowrap;
    }
    .rc-due-pill.rc-due-outstanding {
        background: #fef2f2;   /* red-50 */
        color: #b91c1c;        /* red-700 */
    }
    .rc-due-pill.rc-due-paid {
        background: #f0fdf4;   /* green-50 */
        color: #15803d;        /* green-700 */
    }

    /* ============================================================
       Phase 4 (UI/UX): Branch pill (.rc-branch-pill)
       Inline-styled with the branch color (config/branches.php) by
       the branchPillHtml() JS helper. On mobile only the code shows
       to save space; the full name is hidden.
       ============================================================ */
    .rc-branch-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.1rem 0.55rem;
        border: 1px solid;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 600;
        line-height: 1.3;
        max-width: 100%;
    }
    .rc-branch-pill .rc-branch-name {
        max-width: 9rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .rc-branch-pill .rc-branch-code { opacity: 0.75; font-weight: 700; }
    @media (max-width: 767.98px) {
        .rc-branch-pill .rc-branch-name { display: none; }
        .rc-branch-pill .rc-branch-code { display: inline; }
    }

    /* ============================================================
       Phase 5 (UI/UX): Keyboard-focus row highlight
       Applied to the <tr> focused via j/k shortcuts. The amber tint
       matches the page accent; the inset box-shadow acts as a visible
       focus ring that doesn't get clipped by the overflow container.
       ============================================================ */
    #invoiceTable tbody tr.rc-row-focused {
        background: #fef3c7 !important;          /* amber-100 */
        box-shadow: inset 0 0 0 2px #f59e0b;     /* amber-500 ring */
        outline: none;
    }
    #invoiceTable tbody tr.rc-row-focused > td { background: transparent; }
    /* Respect reduced-motion: no smooth scroll into view. */
    @media (prefers-reduced-motion: reduce) {
        #invoiceTable tbody tr.rc-row-focused { transition: none; }
    }

    /* ============================================================
       Phase 5 (UI/UX): Keyboard-shortcut hint badge
       A small, unobtrusive indicator shown next to the Invoices card
       title so power users discover the j/k/r/c//Esc shortcuts. Hidden
       on touch-primary devices (the shortcut layer is disabled there).
       ============================================================ */
    .rc-kbd-hint {
        display: none;   /* shown via JS only when SHORTCUTS_ENABLED */
        align-items: center;
        gap: 0.25rem;
        margin-left: 0.5rem;
        font-size: 0.7rem;
        color: #6b7280;
    }
    .rc-kbd-hint kbd {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.1rem;
        height: 1.1rem;
        padding: 0 0.25rem;
        border: 1px solid #d1d5db;
        border-bottom-width: 2px;
        border-radius: 0.25rem;
        background: #f9fafb;
        color: #374151;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 0.65rem;
        line-height: 1;
    }
    @media (pointer: coarse) { .rc-kbd-hint { display: none !important; } }

    /* ============================================================
       Phase 5 (UI/UX): Reduced-motion — global guard
       Disables all CSS transitions/animations on this page when the
       user prefers reduced motion. (The bulk-bar slide already had its
       own guard; this covers chips, action buttons, pills, and the
       sticky-header blur.)
       ============================================================ */
    @media (prefers-reduced-motion: reduce) {
        .status-chip,
        .rc-action-btn,
        .rc-due-pill,
        .rc-branch-pill,
        #invoiceTable thead th,
        .sales-invoice-card {
            transition: none !important;
            animation: none !important;
        }
        .rc-table-scroll { scroll-behavior: auto !important; }
        html { scroll-behavior: auto !important; }
    }

    /* ============================================================
       Phase 6 (UI/UX): Collapsible filter card
       Uses native <details>/<summary> for toggle semantics. On mobile
       (<768px) the body collapses by default (JS removes [open] on
       load); on desktop (≥768px) the body is always visible (CSS
       forces it). The chevron rotates 180° when [open].
       ============================================================ */
    .rc-collapsible-card > summary {
        list-style: none;           /* hide default disclosure triangle */
    }
    .rc-collapsible-card > summary::-webkit-details-marker {
        display: none;              /* Safari/Chrome marker */
    }
    .rc-collapsible-card > summary:focus-visible {
        outline: 2px solid #f59e0b;
        outline-offset: -2px;
        border-radius: 0.25rem;
    }
    .rc-collapsible-chevron {
        transition: transform 0.18s ease;
        font-size: 0.8rem;
    }
    .rc-collapsible-card[open] > summary .rc-collapsible-chevron {
        transform: rotate(180deg);
    }
    /* Desktop: always show the body (even if the user collapsed it,
       we respect user intent — but we DO hide the chevron toggle on
       desktop since there's nothing to toggle). */
    @media (min-width: 768px) {
        .rc-collapsible-card .rc-collapsible-body { display: block !important; }
        .rc-collapsible-card > summary .rc-collapsible-chevron { display: none; }
        .rc-collapsible-card > summary { cursor: default; }
    }
    @media (max-width: 767.98px) {
        .rc-collapsible-card .rc-collapsible-body { padding: 0.75rem; }
    }

    /* ============================================================
       Phase 6 (UI/UX): Mobile receive-modal layout
       On <768px the stat tiles stack vertically, the payment-mode
       radios become a 2-col grid, and the submit button sticks to
       the bottom of the modal viewport.
       ============================================================ */
    @media (max-width: 767.98px) {
        #receivePaymentModal .modal-dialog { max-width: 100%; margin: 0; }
        #receivePaymentModal .modal-content { border-radius: 0; min-height: 100vh; }
        #receivePaymentModal .modal-body { max-height: calc(100vh - 8rem); }
        /* Stat tiles: stack vertically on mobile. */
        #receivePaymentModal .rc-modal-stats { row-gap: 0.4rem; }
        #receivePaymentModal .rc-modal-stats > div { width: 100%; }
        /* Payment-mode radios: 2-col grid on mobile. */
        #receivePaymentModal .rc-modal-modes {
            display: grid !important;
            grid-template-columns: 1fr 1fr;
            gap: 0.4rem;
        }
        /* Sticky submit footer on mobile. */
        #receivePaymentModal .modal-footer {
            position: sticky;
            bottom: 0;
            background: #fff;
            border-top: 1px solid #e5e7eb;
            padding: 0.6rem 1rem;
            z-index: 5;
        }
        #receivePaymentModal .modal-footer .btn { flex: 1; }
    }

    /* Phase 6: prevent horizontal scroll on the page at any breakpoint. */
    .sales-invoices-app { overflow-x: hidden; }
    #invoiceTable { overflow-x: auto; }
</style>
@endpush
