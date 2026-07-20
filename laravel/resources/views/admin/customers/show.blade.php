@extends('layouts.admin')

@section('content')

@push('css')
<style>
    /* ── Hero ── */
    .hub-hero {
        background: linear-gradient(135deg, #1e3a5f 0%, #2c8a6e 100%);
        color: #fff; border-radius: .75rem; padding: 1.5rem 2rem;
        display: flex; justify-content: space-between; flex-wrap: wrap;
        gap: 1rem; align-items: center; margin-bottom: 1.25rem;
    }
    .hub-hero h1 { font-size: 1.6rem; margin: 0 0 .3rem; font-weight: 700; }
    .hub-hero p  { margin: 0; opacity: .85; font-size: .88rem; }
    .hub-hero .hero-badge {
        display: inline-flex; align-items: center; gap: .35rem;
        background: rgba(255,255,255,.15); padding: .25rem .65rem;
        border-radius: 1rem; font-size: .8rem; margin-top: .3rem;
    }
    .hub-hero-actions { display: flex; gap: .5rem; flex-wrap: wrap; }

    /* ── KPI Cards ── */
    .hub-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: .75rem; margin-bottom: 1.25rem; }
    .hub-kpi { background: #fff; border: 1px solid #e7eaf0; border-radius: .65rem; padding: .9rem 1rem; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
    .hub-kpi-label { color: #6b7280; font-size: .78rem; text-transform: uppercase; letter-spacing: .3px; margin-bottom: .3rem; }
    .hub-kpi-value { font-size: 1.15rem; font-weight: 700; color: #0f172a; line-height: 1.2; }
    .hub-kpi-sub   { font-size: .75rem; color: #9ca3af; margin-top: .15rem; }

    /* ── Credit utilization bar ── */
    .credit-bar-wrap { background: #e5e7eb; border-radius: .35rem; height: 8px; margin-top: .4rem; overflow: hidden; }
    .credit-bar-fill { height: 100%; border-radius: .35rem; transition: width .4s ease; }
    .credit-bar-fill.safe   { background: #22c55e; }
    .credit-bar-fill.warn   { background: #f59e0b; }
    .credit-bar-fill.danger { background: #ef4444; }

    /* ── Tab Navigation ── */
    .hub-tabs { display: flex; border-bottom: 2px solid #e5e7eb; margin-bottom: 1rem; gap: 0; overflow-x: auto; }
    .hub-tab {
        padding: .6rem 1.2rem; font-size: .85rem; font-weight: 600;
        color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -2px;
        cursor: pointer; white-space: nowrap; transition: all .2s;
        background: none; border-top: none; border-left: none; border-right: none;
    }
    .hub-tab:hover { color: #1e3a5f; }
    .hub-tab.active { color: #1e3a5f; border-bottom-color: #1e3a5f; }
    .hub-tab .tab-count {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 20px; height: 20px; border-radius: 10px;
        font-size: .7rem; font-weight: 700; margin-left: .4rem; padding: 0 5px;
    }
    .hub-tab .tab-count.teal  { background: #d1fae5; color: #065f46; }
    .hub-tab .tab-count.amber { background: #fef3c7; color: #92400e; }
    .hub-tab .tab-count.blue  { background: #dbeafe; color: #1e40af; }
    .hub-tab .tab-count.rose  { background: #fce7f3; color: #9d174d; }

    /* ── Tab Panels ── */
    .hub-panel { display: none; }
    .hub-panel.active { display: block; }

    /* ── Overview detail grid ── */
    .hub-detail-grid {
        background: #fff; border: 1px solid #e7eaf0; border-radius: .65rem;
        box-shadow: 0 1px 3px rgba(15,23,42,.05); padding: 1.25rem 1.5rem; margin-bottom: 1rem;
    }
    .hub-detail-row { display: grid; grid-template-columns: 180px 1fr; gap: .75rem; padding: .5rem 0; border-bottom: 1px solid #f1f5f9; }
    .hub-detail-row:last-child { border-bottom: 0; }
    .hub-detail-label { color: #6b7280; font-size: .85rem; }
    .hub-detail-value { font-weight: 600; color: #0f172a; word-break: break-word; }

    /* ── DataTables overrides ── */
    .hub-panel table.dataTable { font-size: .84rem; }
    .hub-panel table.dataTable th { font-weight: 700; color: #374151; text-transform: uppercase; font-size: .73rem; letter-spacing: .3px; }
    .hub-panel table.dataTable td { vertical-align: middle; }

    /* ── Misc ── */
    .code-pill { display: inline-block; background: #eef2f7; color: #334155; padding: .15rem .55rem; border-radius: 1rem; font-size: .78rem; font-weight: 600; }
    .status-pill { display: inline-flex; align-items: center; gap: .3rem; padding: .15rem .55rem; border-radius: 1rem; font-size: .75rem; font-weight: 600; }
    .status-pill.active   { background: #dcfce7; color: #166534; }
    .status-pill.inactive { background: #fee2e2; color: #991b1b; }
    .status-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .reversed-line { text-decoration: line-through; opacity: .55; }
    .link-code { color: #2563eb; text-decoration: none; font-weight: 600; }
    .link-code:hover { text-decoration: underline; }
    .debit-val  { color: #dc2626; font-weight: 600; }
    .credit-val { color: #16a34a; font-weight: 600; }
    .amount-positive { color: #16a34a; }
    .amount-negative { color: #dc2626; }
</style>
@endpush

@php
    $isActive = (bool) ($item->is_active ?? false);
    $arColor  = $arBalance > ($item->credit_limit ?? 0) ? 'danger' : ($arBalance > 0 ? 'warn' : 'safe');
@endphp

{{-- ════════ HERO ════════ --}}
<div class="hub-hero">
    <div>
        <h1><i class="fas fa-store me-2"></i>{{ $item->customer_name ?? 'Customer' }}</h1>
        <p>Customer 360 Hub — master data, AR ledger, invoices, payments & returns at a glance.</p>
        <span class="hero-badge">
            <i class="fas {{ $isActive ? 'fa-circle-check' : 'fa-circle-xmark' }}"></i>
            {{ $isActive ? 'Active' : 'Inactive' }} · {{ $item->customer_code ?? '—' }}
        </span>
    </div>
    <div class="hub-hero-actions">
        @can('admin')
        <a href="{{ route("{$routePrefix}.edit", $item) }}" class="btn btn-light btn-sm">
            <i class="fas fa-pen me-1"></i> Edit
        </a>
        @endcan
        <a href="{{ route('admin.customer-payments.create') }}?customer_id={{ $item->id }}" class="btn btn-outline-light btn-sm">
            <i class="fas fa-money-bill-wave me-1"></i> New Payment
        </a>
        <a href="{{ route("{$routePrefix}.index") }}" class="btn btn-outline-light btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Directory
        </a>
    </div>
</div>

{{-- ════════ KPI CARDS ════════ --}}
<div class="hub-kpis">
    <div class="hub-kpi">
        <div class="hub-kpi-label">AR Balance</div>
        <div class="hub-kpi-value {{ $arBalance > 0 ? 'amount-negative' : 'amount-positive' }}">Tk {{ number_format($arBalance, 2) }}</div>
        <div class="credit-bar-wrap">
            <div class="credit-bar-fill {{ $arColor }}" style="width: {{ min($creditUtilization, 100) }}%"></div>
        </div>
        <div class="hub-kpi-sub">{{ $creditUtilization > 999 ? 'Over limit' : $creditUtilization . '% of credit limit' }}</div>
    </div>
    <div class="hub-kpi">
        <div class="hub-kpi-label">Credit Limit</div>
        <div class="hub-kpi-value">Tk {{ number_format((float) ($item->credit_limit ?? 0), 0) }}</div>
        <div class="hub-kpi-sub">{{ $item->balance_type ? ucfirst($item->balance_type) . ' opening' : '' }}</div>
    </div>
    <div class="hub-kpi">
        <div class="hub-kpi-label">Total Invoiced</div>
        <div class="hub-kpi-value">Tk {{ number_format($totalInvoiced, 0) }}</div>
        <div class="hub-kpi-sub">Lifetime, excluding cancelled</div>
    </div>
    <div class="hub-kpi">
        <div class="hub-kpi-label">Total Paid</div>
        <div class="hub-kpi-value amount-positive">Tk {{ number_format($totalPaid, 0) }}</div>
        <div class="hub-kpi-sub">All confirmed payments</div>
    </div>
    <div class="hub-kpi">
        <div class="hub-kpi-label">Open Invoices</div>
        <div class="hub-kpi-value {{ $openInvoices > 0 ? 'amount-negative' : '' }}">{{ $openInvoices }}</div>
        <div class="hub-kpi-sub">With outstanding due</div>
    </div>
    <div class="hub-kpi">
        <div class="hub-kpi-label">Last Payment</div>
        <div class="hub-kpi-value">{{ $lastPayment ? \Carbon\Carbon::parse($lastPayment->payment_date)->format('d M Y') : '—' }}</div>
        <div class="hub-kpi-sub">{{ $lastPayment ? 'Tk ' . number_format((float) $lastPayment->amount, 0) : 'No payments yet' }}</div>
    </div>
    <div class="hub-kpi">
        <div class="hub-kpi-label">Total Returns</div>
        <div class="hub-kpi-value {{ $totalReturns > 0 ? 'amount-negative' : '' }}">Tk {{ number_format($totalReturns, 0) }}</div>
        <div class="hub-kpi-sub">Confirmed returns value</div>
    </div>
</div>

{{-- ════════ TAB NAV ════════ --}}
<div class="hub-tabs" role="tablist">
    <button class="hub-tab active" data-tab="overview" role="tab" aria-selected="true">
        <i class="fas fa-id-card me-1"></i> Overview
    </button>
    <button class="hub-tab" data-tab="ledger" role="tab">
        <i class="fas fa-book me-1"></i> Ledger <span class="tab-count teal" id="ledger-count">—</span>
    </button>
    <button class="hub-tab" data-tab="invoices" role="tab">
        <i class="fas fa-file-invoice me-1"></i> Invoices <span class="tab-count amber" id="invoices-count">—</span>
    </button>
    <button class="hub-tab" data-tab="payments" role="tab">
        <i class="fas fa-money-check-alt me-1"></i> Payments <span class="tab-count blue" id="payments-count">—</span>
    </button>
    <button class="hub-tab" data-tab="returns" role="tab">
        <i class="fas fa-undo-alt me-1"></i> Returns <span class="tab-count rose" id="returns-count">—</span>
    </button>
</div>

{{-- ════════ TAB: OVERVIEW ════════ --}}
<div class="hub-panel active" id="panel-overview">
    <div class="hub-detail-grid">
        <div class="hub-detail-row">
            <div class="hub-detail-label">Customer code</div>
            <div class="hub-detail-value">
                @if (! empty($item->customer_code))
                    <span class="code-pill">{{ $item->customer_code }}</span>
                @else
                    <span class="text-muted">—</span>
                @endif
            </div>
        </div>
        <div class="hub-detail-row">
            <div class="hub-detail-label">Customer name</div>
            <div class="hub-detail-value">{{ $item->customer_name ?? '—' }}</div>
        </div>
        <div class="hub-detail-row">
            <div class="hub-detail-label">Mobile</div>
            <div class="hub-detail-value">
                @if (! empty($item->mobile))
                    <a href="tel:{{ $item->mobile }}" class="text-decoration-none"><i class="fas fa-phone me-1 text-muted"></i>{{ $item->mobile }}</a>
                @else
                    <span class="text-muted">—</span>
                @endif
            </div>
        </div>
        <div class="hub-detail-row">
            <div class="hub-detail-label">Phone</div>
            <div class="hub-detail-value">{{ $item->phone ?? '—' }}</div>
        </div>
        <div class="hub-detail-row">
            <div class="hub-detail-label">Email</div>
            <div class="hub-detail-value">
                @if (! empty($item->email))
                    <a href="mailto:{{ $item->email }}" class="text-decoration-none">{{ $item->email }}</a>
                @else
                    <span class="text-muted">—</span>
                @endif
            </div>
        </div>
        <div class="hub-detail-row">
            <div class="hub-detail-label">Address</div>
            <div class="hub-detail-value">{!! nl2br(e($item->address ?? '')) !!}</div>
        </div>
        <div class="hub-detail-row">
            <div class="hub-detail-label">Branch</div>
            <div class="hub-detail-value">{{ $item->branch?->branch_name ?? '—' }}</div>
        </div>
        <div class="hub-detail-row">
            <div class="hub-detail-label">Sales person</div>
            <div class="hub-detail-value">{{ $item->salesPerson?->name ?? '—' }}</div>
        </div>
        <div class="hub-detail-row">
            <div class="hub-detail-label">Credit limit</div>
            <div class="hub-detail-value">Tk {{ number_format((float) ($item->credit_limit ?? 0), 2) }}</div>
        </div>
        <div class="hub-detail-row">
            <div class="hub-detail-label">Opening balance</div>
            <div class="hub-detail-value">
                Tk {{ number_format((float) ($item->opening_balance ?? 0), 2) }}
                @if (! empty($item->balance_type))
                    <span class="badge bg-secondary ms-1">{{ ucfirst($item->balance_type) }}</span>
                @endif
            </div>
        </div>
        <div class="hub-detail-row">
            <div class="hub-detail-label">AR Balance (live)</div>
            <div class="hub-detail-value {{ $arBalance > 0 ? 'amount-negative' : 'amount-positive' }}">
                Tk {{ number_format($arBalance, 2) }}
            </div>
        </div>
        <div class="hub-detail-row">
            <div class="hub-detail-label">Status</div>
            <div class="hub-detail-value">
                @if ($isActive)
                    <span class="status-pill active"><span class="dot"></span> Active</span>
                @else
                    <span class="status-pill inactive"><span class="dot"></span> Inactive</span>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ════════ TAB: LEDGER ════════ --}}
<div class="hub-panel" id="panel-ledger">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h6 class="mb-0" style="font-weight:700;">Customer Ledger — AR Running Balance</h6>
        <div class="d-flex gap-2 align-items-center">
            <input type="date" id="ledger-from" class="form-control form-control-sm" style="width:140px" placeholder="From">
            <input type="date" id="ledger-to" class="form-control form-control-sm" style="width:140px" placeholder="To">
            <button class="btn btn-sm btn-outline-primary" id="ledger-filter-btn"><i class="fas fa-filter me-1"></i>Filter</button>
        </div>
    </div>
    <table id="ledger-table" class="table table-hover table-bordered" style="width:100%">
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Ref</th>
                <th>Debit</th>
                <th>Credit</th>
                <th>Balance</th>
                <th>Description</th>
            </tr>
        </thead>
    </table>
</div>

{{-- ════════ TAB: INVOICES ════════ --}}
<div class="hub-panel" id="panel-invoices">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h6 class="mb-0" style="font-weight:700;">Sales Invoices</h6>
        <select id="invoice-status-filter" class="form-select form-select-sm" style="width:150px">
            <option value="">All statuses</option>
            <option value="confirmed">Confirmed</option>
            <option value="draft">Draft</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>
    <table id="invoices-table" class="table table-hover table-bordered" style="width:100%">
        <thead>
            <tr>
                <th>Invoice</th>
                <th>Date</th>
                <th>Salesman</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Due</th>
                <th>Status</th>
            </tr>
        </thead>
    </table>
</div>

{{-- ════════ TAB: PAYMENTS ════════ --}}
<div class="hub-panel" id="panel-payments">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h6 class="mb-0" style="font-weight:700;">Payments Received</h6>
        <select id="payment-type-filter" class="form-select form-select-sm" style="width:150px">
            <option value="">All types</option>
            <option value="receive">Receive</option>
            <option value="discount">Discount</option>
            <option value="write_off">Write-Off</option>
            <option value="payment">Refund</option>
        </select>
    </div>
    <table id="payments-table" class="table table-hover table-bordered" style="width:100%">
        <thead>
            <tr>
                <th>Payment</th>
                <th>Date</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Discount</th>
                <th>Mode</th>
                <th>Status</th>
                <th>Ref No</th>
            </tr>
        </thead>
    </table>
</div>

{{-- ════════ TAB: RETURNS ════════ --}}
<div class="hub-panel" id="panel-returns">
    <h6 class="mb-2" style="font-weight:700;">Sales Returns</h6>
    <table id="returns-table" class="table table-hover table-bordered" style="width:100%">
        <thead>
            <tr>
                <th>Return</th>
                <th>Date</th>
                <th>Amount</th>
                <th>COGS</th>
                <th>Status</th>
                <th>Reason</th>
            </tr>
        </thead>
    </table>
</div>

{{-- ════════ Bottom actions ════════ --}}
<div class="d-flex gap-2 flex-wrap mt-3">
    @can('admin')
    <a href="{{ route("{$routePrefix}.edit", $item) }}" class="btn btn-primary">
        <i class="fas fa-pen me-1"></i> Edit customer
    </a>
    @endcan
    <a href="{{ route('admin.customer-payments.create') }}?customer_id={{ $item->id }}" class="btn btn-success">
        <i class="fas fa-money-bill-wave me-1"></i> Receive payment
    </a>
    <a href="{{ route("{$routePrefix}.index") }}" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to directory
    </a>
</div>

@push('scripts')
<script>
$(function () {
    const customerId = {{ $item->id }};

    // ── Tab switching ──
    const tabs = document.querySelectorAll('.hub-tab');
    const panels = document.querySelectorAll('.hub-panel');
    let initialized = { overview: true, ledger: false, invoices: false, payments: false, returns: false };

    tabs.forEach(tab => {
        tab.addEventListener('click', function () {
            const target = this.dataset.tab;
            tabs.forEach(t => { t.classList.remove('active'); t.setAttribute('aria-selected', 'false'); });
            panels.forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');
            document.getElementById('panel-' + target).classList.add('active');

            // Lazy-init DataTable on first visit
            if (!initialized[target]) {
                if (target === 'ledger')   initLedgerTable();
                if (target === 'invoices') initInvoicesTable();
                if (target === 'payments') initPaymentsTable();
                if (target === 'returns')  initReturnsTable();
                initialized[target] = true;
            }
        });
    });

    // ── Ledger DataTable ──
    let ledgerTable;
    function initLedgerTable() {
        ledgerTable = $('#ledger-table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route("admin.customers.ledger-data", $item->id) }}',
                data: function (d) {
                    d.from = $('#ledger-from').val();
                    d.to   = $('#ledger-to').val();
                }
            },
            order: [[0, 'desc']],
            columns: [
                { data: 'transaction_date' },
                { data: 'transaction_type' },
                { data: null, render: function (d) {
                    return d.reference_type + ' #' + d.reference_id;
                }},
                { data: 'debit',  className: 'text-end', render: function (v) {
                    return v !== '—' ? '<span class="debit-val">' + v + '</span>' : '—';
                }},
                { data: 'credit', className: 'text-end', render: function (v) {
                    return v !== '—' ? '<span class="credit-val">' + v + '</span>' : '—';
                }},
                { data: 'balance', className: 'text-end fw-bold' },
                { data: 'description' },
            ],
            drawCallback: function (settings) {
                $('#ledger-count').text(settings.json?.recordsFiltered ?? '0');
            }
        });
    }

    // Ledger date filter
    $('#ledger-filter-btn').on('click', function () {
        if (ledgerTable) ledgerTable.ajax.reload();
    });

    // ── Invoices DataTable ──
    let invoicesTable;
    function initInvoicesTable() {
        invoicesTable = $('#invoices-table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route("admin.customers.invoices-data", $item->id) }}',
                data: function (d) {
                    d.status = $('#invoice-status-filter').val();
                }
            },
            order: [[1, 'desc']],
            columns: [
                { data: null, render: function (d) {
                    const cls = d.is_reversed ? 'reversed-line' : '';
                    return '<a href="' + d.show_url + '" class="link-code ' + cls + '">' + d.invoice_code + '</a>';
                }},
                { data: 'invoice_date' },
                { data: 'salesman' },
                { data: 'total_amount', className: 'text-end' },
                { data: 'paid_amount',  className: 'text-end' },
                { data: 'due_amount',   className: 'text-end', render: function (v, t, d) {
                    const val = parseFloat(v.replace(/,/g, ''));
                    return val > 0 ? '<span class="amount-negative">' + v + '</span>' : v;
                }},
                { data: null, render: function (d) {
                    let badge = '<span class="badge ' + d.status_class + '">' + d.status + '</span>';
                    if (d.is_reversed) badge += ' <span class="badge bg-dark">Reversed</span>';
                    return badge;
                }},
            ],
            drawCallback: function (settings) {
                $('#invoices-count').text(settings.json?.recordsFiltered ?? '0');
            }
        });
    }

    // Invoice status filter
    $('#invoice-status-filter').on('change', function () {
        if (invoicesTable) invoicesTable.ajax.reload();
    });

    // ── Payments DataTable ──
    let paymentsTable;
    function initPaymentsTable() {
        paymentsTable = $('#payments-table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route("admin.customers.payments-data", $item->id) }}',
                data: function (d) {
                    d.type = $('#payment-type-filter').val();
                }
            },
            order: [[1, 'desc']],
            columns: [
                { data: null, render: function (d) {
                    const cls = d.is_reversed ? 'reversed-line' : '';
                    return '<a href="' + d.show_url + '" class="link-code ' + cls + '">' + d.payment_code + '</a>';
                }},
                { data: 'payment_date' },
                { data: null, render: function (d) {
                    return '<span class="badge ' + d.type_class + '">' + d.transaction_type + '</span>';
                }},
                { data: 'amount', className: 'text-end' },
                { data: 'discount_amount', className: 'text-end' },
                { data: 'payment_mode' },
                { data: null, render: function (d) {
                    let badge = '<span class="badge ' + d.status_class + '">' + d.status + '</span>';
                    if (d.is_reversed) badge += ' <span class="badge bg-dark">Reversed</span>';
                    return badge;
                }},
                { data: 'reference_no' },
            ],
            drawCallback: function (settings) {
                $('#payments-count').text(settings.json?.recordsFiltered ?? '0');
            }
        });
    }

    // Payment type filter
    $('#payment-type-filter').on('change', function () {
        if (paymentsTable) paymentsTable.ajax.reload();
    });

    // ── Returns DataTable ──
    let returnsTable;
    function initReturnsTable() {
        returnsTable = $('#returns-table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route("admin.customers.returns-data", $item->id) }}'
            },
            order: [[1, 'desc']],
            columns: [
                { data: 'return_code', render: function (v, t, d) {
                    const cls = d.is_reversed ? 'reversed-line' : '';
                    return '<span class="link-code ' + cls + '">' + v + '</span>';
                }},
                { data: 'return_date' },
                { data: 'total_amount', className: 'text-end' },
                { data: 'cogs_amount',  className: 'text-end' },
                { data: null, render: function (d) {
                    let badge = '<span class="badge ' + d.status_class + '">' + d.status + '</span>';
                    if (d.is_reversed) badge += ' <span class="badge bg-dark">Reversed</span>';
                    return badge;
                }},
                { data: 'reason' },
            ],
            drawCallback: function (settings) {
                $('#returns-count').text(settings.json?.recordsFiltered ?? '0');
            }
        });
    }
});
</script>
@endpush

@endsection
