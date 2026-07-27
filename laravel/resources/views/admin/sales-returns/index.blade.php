@extends('layouts.admin')

@push('css')
<link rel="stylesheet" href="/assets/css/sales-return-index.css?v={{ filemtime(public_path('assets/css/sales-return-index.css')) }}">
<link rel="stylesheet" href="/assets/css/sales-return-create.css?v={{ filemtime(public_path('assets/css/sales-return-create.css')) }}">
@endpush

@section('content')
@php
    // Phase 3.3 — defaults for the smart-filter state.
    // 4 status chips: all / created (Pending) / confirmed / reversed.
    // Mirrors Purchase Return's filter shape but with the extra "created"
    // state (Purchase Return only has active/reversed because its workflow
    // auto-confirms on create; Sales Return is two-phase: created → confirmed).
    $filters = array_merge([
        'date_from'   => now()->format('Y-m-d'),
        'date_to'     => now()->format('Y-m-d'),
        'status'      => 'all',
        'date_preset' => 'today',
        'search'      => '',
        'smart_sort'  => true,
    ], is_array($filters ?? null) ? $filters : []);

    $branchName = $session_branch_name ?? (auth()->user()?->branch?->branch_name ?? 'Branch');
    $today      = now()->format('Y-m-d');
    $csrf       = csrf_token();

    // URL params that override localStorage persistence.
    $forceUrlParams = request()->hasAny(['date_from', 'date_to', 'status', 'q', 'invoice_code']);

    // Pre-fill term for the offcanvas workspace (deep-link from invoice show page).
    $prefill = trim((string) (request()->input('invoice_code') ?? request()->input('q') ?? ''));

    $smartSort = (bool) ($filters['smart_sort'] ?? true);

    // BUG-45: Blade's @json() can't safely encode multi-key array literals,
    // so we json_encode in @php and emit via {!! !!}.
    $createBoot = json_encode([
        'workspace_id' => 'salesReturnOffcanvasRoot',
        'prefill'      => $prefill,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $mainBoot = json_encode([
        'date_from'      => $filters['date_from'] ?? $today,
        'date_to'        => $filters['date_to']   ?? $today,
        'status'         => $filters['status']    ?? 'all',
        'search'         => $filters['search']    ?? '',
        'smart_sort'     => $smartSort,
        'date_preset'    => $filters['date_preset'] ?? 'today',
        'forceUrlParams' => $forceUrlParams,
        'csrf'           => $csrf,
        'endpoints'      => [
            'datatables'      => route('admin.sales-returns.index'),
            'summary'         => route('admin.sales-returns.summary'),
            'search_invoices' => route('admin.sales-returns.search-invoices'),
            'invoice_details' => route('admin.sales-returns.invoice-details'),
            'store'           => route('admin.sales-returns.store'),
            'reverse'         => '',
            'show'            => '',
            'export'          => route('admin.sales-returns.export'),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
@endphp

<div id="sales-return-app" class="sales-return-app container-fluid py-2">
    {{-- Hero header (orange→red gradient) --}}
    <header class="sales-return-hero">
        <div>
            <h1><i class="fas fa-undo-alt me-2"></i>{{ $title }}</h1>
            <p>Customer returns — stock IN at the <em>ORIGINAL avg_cost</em> from the challan (not current avg_cost)</p>
            <span class="sales-return-branch-tag">
                <i class="fas fa-map-marker-alt me-1"></i>{{ e($branchName) }}
            </span>
            {{-- Two-step journey indicator --}}
            <div class="sr-journey-steps sr-journey-steps--hero">
                <span class="sr-journey-step is-active">
                    <span class="sr-journey-num">1</span> Created
                </span>
                <i class="fas fa-chevron-right sr-journey-arrow"></i>
                <span class="sr-journey-step is-muted">
                    <span class="sr-journey-num">2</span> Confirmed
                </span>
                <i class="fas fa-chevron-right sr-journey-arrow"></i>
                <span class="sr-journey-step is-muted">
                    <span class="sr-journey-num">3</span> Reversed
                </span>
            </div>
        </div>
        <div class="sales-return-hero-actions">
            <button type="button"
                    class="btn btn-light btn-sm"
                    id="openSalesReturnCreate"
                    data-bs-toggle="offcanvas"
                    data-bs-target="#salesReturnCreateOffcanvas"
                    aria-controls="salesReturnCreateOffcanvas">
                <i class="fas fa-plus"></i> Return
            </button>
            <a href="{{ route('admin.sales-returns.create') }}"
               class="btn btn-light btn-sm d-none d-md-inline-flex"
               title="Full page return">
                <i class="fas fa-external-link-alt"></i>
            </a>
            <a href="{{ route('admin.sales-returns.export', request()->only(['date_from', 'date_to', 'search', 'status'])) }}"
               class="btn btn-light btn-sm"
               title="Export CSV">
                <i class="fas fa-file-csv"></i>
            </a>
            <button type="button"
                    class="btn btn-light btn-sm collapsed"
                    id="toggleSalesReturnFilters"
                    data-bs-toggle="collapse"
                    data-bs-target="#salesReturnFiltersCollapse"
                    aria-expanded="false"
                    aria-controls="salesReturnFiltersCollapse"
                    title="Filters">
                <i class="fas fa-filter me-1"></i>Filters
            </button>
        </div>
    </header>

    {{-- Smart filter panel (collapsible) --}}
    <section class="sales-return-filters-shell">
        <div class="collapse" id="salesReturnFiltersCollapse">
            <div class="sales-return-smart-panel">
                <div class="sales-return-smart-label">Quick period</div>
                <div class="sales-return-preset-row">
                    <button type="button" class="sales-return-preset-btn active" data-preset="today">Today</button>
                    <button type="button" class="sales-return-preset-btn" data-preset="yesterday">Yesterday</button>
                    <button type="button" class="sales-return-preset-btn" data-preset="week">Last 7 days</button>
                    <button type="button" class="sales-return-preset-btn" data-preset="month">This month</button>
                    <button type="button" class="sales-return-preset-btn" data-preset="custom">Custom</button>
                </div>

                <div class="sales-return-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search"
                           id="filterSearch"
                           class="form-control sales-return-search-input"
                           placeholder="Smart search — return #, invoice, customer…"
                           value="{{ e($filters['search'] ?? '') }}"
                           autocomplete="off">
                </div>

                <div class="sales-return-smart-label">
                    Status <small class="text-muted fw-normal">(live counts)</small>
                </div>
                <div class="sales-return-status-chips mb-3">
                    <button type="button" class="sales-return-status-chip active" data-status="all">
                        <span>All</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="sales-return-status-chip chip-pending" data-status="created">
                        <span>Pending</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="sales-return-status-chip chip-confirmed" data-status="confirmed">
                        <span>Confirmed</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="sales-return-status-chip chip-reversed" data-status="reversed">
                        <span>Reversed</span><span class="chip-count">0</span>
                    </button>
                </div>
                <input type="hidden" id="filterStatus" value="{{ e($filters['status'] ?? 'all') }}">

                <div class="mt-3 pt-3 border-top">
                    <div class="row g-2 align-items-end">
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-0" for="filterDateFrom">From</label>
                            <input type="date" id="filterDateFrom" class="form-control"
                                   value="{{ e($filters['date_from'] ?? $today) }}">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-0" for="filterDateTo">To</label>
                            <input type="date" id="filterDateTo" class="form-control"
                                   value="{{ e($filters['date_to'] ?? $today) }}">
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="form-check mt-2 mt-md-4">
                                <input class="form-check-input" type="checkbox" id="filterSmartSort" checked>
                                <label class="form-check-label small" for="filterSmartSort">
                                    Priority sort — active first, then reversed
                                </label>
                            </div>
                        </div>
                        <div class="col-12 col-md-2">
                            <button type="button" id="clearFilters" class="btn btn-outline-secondary w-100">Reset all</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Active filter bar (populated by JS) --}}
    <div class="sales-return-active-bar" id="activeFilterBar"></div>

    {{-- Results card --}}
    <section class="sales-return-results-card">
        <div class="sales-return-results-head">
            <div class="fw-bold"><span id="resultsCountNum">0</span> return(s)</div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.sales-returns.audit') }}"
                   class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-history me-1"></i> Audit log
                </a>
            </div>
        </div>
        <div class="p-2 p-md-3">
            <div id="returnCards" class="sales-return-mobile-cards"></div>
            <div class="table-responsive sales-dt-mobile-controls">
                <table class="table table-hover align-middle mb-0" id="returnsTable" style="width:100%">
                    <thead>
                        <tr>
                            <th>Return</th>
                            <th>Invoice</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th class="text-end">Amount</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </section>
</div>

{{-- Offcanvas quick-create (uses the shared Phase 4 workspace partial) --}}
<div class="offcanvas offcanvas-end sales-return-create-offcanvas"
     tabindex="-1"
     id="salesReturnCreateOffcanvas"
     aria-labelledby="salesReturnCreateOffcanvasLabel">
    <div class="offcanvas-header">
        <div>
            <h5 class="offcanvas-title mb-0" id="salesReturnCreateOffcanvasLabel">
                <i class="fas fa-undo-alt me-2"></i>Quick return
            </h5>
            <p class="mb-0 small opacity-90">Search invoice → enter return qty &amp; warehouse → save</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body pt-2">
        @include('admin.sales-returns.partials.create-workspace', [
            'workspaceId' => 'salesReturnOffcanvasRoot',
            'compact'     => true,
        ])
    </div>
</div>
@endsection

@push('scripts')
<script>window.CSRF_TOKEN = @json($csrf);</script>
<script>
window.SALES_RETURN_BASE = '{{ rtrim(route('admin.sales-returns.index'), '/') }}/';
window.SALES_RETURN_CREATE_BOOT = {!! $createBoot !!};
window.SALES_RETURN_BOOT = {!! $mainBoot !!};
</script>
{{-- SalesReturn.js — workspace IIFE (shared with create page). Auto-binds to [data-srt-workspace]. --}}
<script src="/assets/js/SalesReturn.js?v={{ filemtime(public_path('assets/js/SalesReturn.js')) }}"></script>
{{-- sales-return-index.js — index page JS (filters, chips, DataTables, reverse SweetAlert, offcanvas bootstrap). --}}
<script src="/assets/js/sales-return-index.js?v={{ filemtime(public_path('assets/js/sales-return-index.js')) }}"></script>
@endpush
