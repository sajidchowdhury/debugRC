@extends('layouts.admin')

@section('content')
@php
    // Defaults for filter controls
    $filters = array_merge([
        'from_date' => '',
        'to_date'   => '',
        'branch_id' => '',
        'search'    => '',
    ], is_array($filters ?? null) ? $filters : []);

    $stats = array_merge([
        'total'           => 0,
        'active'          => 0,
        'reversed'        => 0,
        'total_cogs'      => 0,
        'pending_godown'  => 0,
        'pending_challan' => 0,
    ], $stats ?? []);

    // Determine active tab. Default = 'pending_godown' (the WM's primary queue).
    // Tab persistence via ?tab= query param.
    $activeTab = request()->input('tab', 'pending_godown');
    if (!in_array($activeTab, ['pending_godown', 'pending_challan', 'issued'], true)) {
        $activeTab = 'pending_godown';
    }

    // Helper: count line items + total qty for display
    $invoiceLineCount = function ($invoice) {
        return $invoice->relationLoaded('items') ? $invoice->items->count() : 0;
    };
    $invoiceTotalQty = function ($invoice) {
        if (!$invoice->relationLoaded('items')) return 0;
        return $invoice->items->sum('qty');
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header (purple/indigo = revenue movement) --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-truck me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Warehouse workflow queue: invoices awaiting godown prep, invoices awaiting challan issue, and issued challans history.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.sales-invoices.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-file-invoice-dollar me-1"></i> Invoices
            </a>
        </div>
    </header>

    {{-- Workflow queue stats: 4 cards --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0891b2;">
                        <i class="fas fa-warehouse"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['pending_godown'] ?? 0)) }}</div>
                        <div class="text-muted small">Pending godown prep</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#7c3aed;">
                        <i class="fas fa-truck-front"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['pending_challan'] ?? 0)) }}</div>
                        <div class="text-muted small">Pending challan issue</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['active'] ?? 0)) }}</div>
                        <div class="text-muted small">Issued (active)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#4f46e5;">
                        <i class="fas fa-taka-sign"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) ($stats['total_cogs'] ?? 0), 2) }}</div>
                        <div class="text-muted small">Total COGS (active)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter form (applies to all tabs via ?search=) --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.sales-challans.index') }}" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="{{ $activeTab }}">
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="from_date">From date (issued)</label>
                    <input type="date" id="from_date" name="from_date" class="form-control form-control-sm"
                           value="{{ $filters['from_date'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="to_date">To date (issued)</label>
                    <input type="date" id="to_date" name="to_date" class="form-control form-control-sm"
                           value="{{ $filters['to_date'] }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="search">Search (invoice / challan / customer)</label>
                    <input type="text" id="search" name="search" class="form-control form-control-sm"
                           placeholder="INV-2025-... / CH-... / customer name" value="{{ $filters['search'] }}">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.sales-challans.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                    <a id="csvExportBtn" href="{{ route('admin.sales-challans.export-csv') }}" class="btn btn-outline-success btn-sm" target="_blank">
                        <i class="fas fa-file-csv me-1"></i> Export CSV
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Tabs: 3 sections --}}
    <ul class="nav nav-tabs mb-0" id="challanTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeTab === 'pending_godown' ? 'active' : '' }}"
                    id="pending-godown-tab" data-bs-toggle="tab" data-bs-target="#pending-godown"
                    type="button" role="tab"
                    onclick="switchTab('pending_godown')">
                <i class="fas fa-warehouse me-1"></i> Pending Godown Prep
                @if (!empty($stats['pending_godown']))
                    <span class="badge bg-danger ms-1">{{ number_format((int) $stats['pending_godown']) }}</span>
                @endif
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeTab === 'pending_challan' ? 'active' : '' }}"
                    id="pending-challan-tab" data-bs-toggle="tab" data-bs-target="#pending-challan"
                    type="button" role="tab"
                    onclick="switchTab('pending_challan')">
                <i class="fas fa-truck-front me-1"></i> Pending Challan Issue
                @if (!empty($stats['pending_challan']))
                    <span class="badge bg-warning text-dark ms-1">{{ number_format((int) $stats['pending_challan']) }}</span>
                @endif
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeTab === 'issued' ? 'active' : '' }}"
                    id="issued-tab" data-bs-toggle="tab" data-bs-target="#issued"
                    type="button" role="tab"
                    onclick="switchTab('issued')">
                <i class="fas fa-circle-check me-1"></i> Issued Challans
                <span class="badge bg-secondary ms-1">{{ number_format((int) ($stats['active'] ?? 0)) }}</span>
            </button>
        </li>
    </ul>

    <div class="tab-content shadow-sm border border-top-0 rounded-bottom mb-4" style="min-height: 320px;">
        {{-- ============================================================= --}}
        {{-- TAB 1: Pending Godown Prep --}}
        {{-- ============================================================= --}}
        <div class="tab-pane fade {{ $activeTab === 'pending_godown' ? 'show active' : '' }} p-3"
             id="pending-godown" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <h2 class="h6 mb-0 text-muted">
                        <i class="fas fa-warehouse me-1"></i> Invoices awaiting godown prep
                    </h2>
                    <p class="small text-muted mb-0">
                        Freshly finalized invoices from salesmen. Assign a warehouse to each line, then save to advance the invoice to "Pending Challan Issue".
                    </p>
                </div>
                <span class="badge bg-info-subtle text-info">
                    {{ $pendingGodown->count() }} shown
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Branch</th>
                            <th class="text-center">Lines</th>
                            <th class="text-end">Total Qty</th>
                            <th class="text-end">Amount (Tk)</th>
                            <th>Soft-hold?</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pendingGodown as $inv)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.sales-invoices.show', $inv) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $inv->invoice_code }}
                                    </a>
                                </td>
                                <td class="text-nowrap small">
                                    {{ \Carbon\Carbon::parse($inv->invoice_date)->format('d M Y') }}
                                </td>
                                <td>
                                    @if ($inv->customer)
                                        <span class="fw-semibold">{{ $inv->customer->customer_name }}</span>
                                        <div class="small text-muted">{{ $inv->customer->customer_code }}</div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($inv->branch)
                                        <span class="badge bg-light text-dark border">{{ $inv->branch->branch_code }}</span>
                                        <div class="small text-muted">{{ $inv->branch->branch_name }}</div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">{{ $invoiceLineCount($inv) }}</td>
                                <td class="text-end">{{ number_format((float) $invoiceTotalQty($inv), 2) }}</td>
                                <td class="text-end fw-semibold">Tk {{ number_format((float) $inv->total_amount, 2) }}</td>
                                <td class="text-center">
                                    @if (!empty($inv->is_soft_hold))
                                        <span class="badge bg-warning text-dark" title="Soft-hold: do not dispatch yet">
                                            <i class="fas fa-pause me-1"></i>Hold
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.sales-challans.godown', $inv->id) }}"
                                       class="btn btn-sm btn-primary" title="Prepare godown copy">
                                        <i class="fas fa-warehouse me-1"></i> Prepare Godown
                                    </a>
                                    <a href="{{ route('admin.sales-invoices.show', $inv) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View invoice">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No invoices awaiting godown prep. New finalized invoices from salesmen will appear here.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ============================================================= --}}
        {{-- TAB 2: Pending Challan Issue --}}
        {{-- ============================================================= --}}
        <div class="tab-pane fade {{ $activeTab === 'pending_challan' ? 'show active' : '' }} p-3"
             id="pending-challan" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <h2 class="h6 mb-0 text-muted">
                        <i class="fas fa-truck-front me-1"></i> Invoices awaiting challan issue
                    </h2>
                    <p class="small text-muted mb-0">
                        Godown prep complete (warehouses assigned). Issue the challan to move stock OUT and post COGS.
                    </p>
                </div>
                <span class="badge bg-warning-subtle text-warning">
                    {{ $pendingChallan->count() }} shown
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice</th>
                            <th>Godown Date</th>
                            <th>Customer</th>
                            <th>Branch</th>
                            <th class="text-center">Lines</th>
                            <th class="text-end">Total Qty</th>
                            <th class="text-end">Amount (Tk)</th>
                            <th>Soft-hold?</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pendingChallan as $inv)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.sales-invoices.show', $inv) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $inv->invoice_code }}
                                    </a>
                                </td>
                                <td class="text-nowrap small">
                                    @if ($inv->godown_prepared_at)
                                        {{ \Carbon\Carbon::parse($inv->godown_prepared_at)->format('d M Y, H:i') }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($inv->customer)
                                        <span class="fw-semibold">{{ $inv->customer->customer_name }}</span>
                                        <div class="small text-muted">{{ $inv->customer->customer_code }}</div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($inv->branch)
                                        <span class="badge bg-light text-dark border">{{ $inv->branch->branch_code }}</span>
                                        <div class="small text-muted">{{ $inv->branch->branch_name }}</div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">{{ $invoiceLineCount($inv) }}</td>
                                <td class="text-end">{{ number_format((float) $invoiceTotalQty($inv), 2) }}</td>
                                <td class="text-end fw-semibold">Tk {{ number_format((float) $inv->total_amount, 2) }}</td>
                                <td class="text-center">
                                    @if (!empty($inv->is_soft_hold))
                                        <span class="badge bg-warning text-dark" title="Soft-hold: do not dispatch yet">
                                            <i class="fas fa-pause me-1"></i>Hold
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.sales-challans.challan-form', $inv->id) }}"
                                       class="btn btn-sm btn-success" title="Issue challan (stock OUT + COGS)">
                                        <i class="fas fa-truck me-1"></i> Issue Challan
                                    </a>
                                    <a href="{{ route('admin.sales-invoices.show', $inv) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View invoice">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No invoices awaiting challan issue. Prepare godown on pending invoices to advance them here.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ============================================================= --}}
        {{-- TAB 3: Issued Challans (history) --}}
        {{-- ============================================================= --}}
        <div class="tab-pane fade {{ $activeTab === 'issued' ? 'show active' : '' }} p-3"
             id="issued" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <h2 class="h6 mb-0 text-muted">
                        <i class="fas fa-circle-check me-1"></i> Issued challans history
                    </h2>
                    <p class="small text-muted mb-0">
                        Challans that have been issued (stock moved OUT, COGS posted). Filter by date range.
                    </p>
                </div>
                <span class="badge bg-success-subtle text-success">
                    {{ number_format((int) ($stats['active'] ?? 0)) }} active
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" id="dataTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Date</th>
                            <th>Invoice Code</th>
                            <th>Customer</th>
                            <th>Branch</th>
                            <th class="text-end">COGS Amount (Tk)</th>
                            <th>Transport</th>
                            <th class="text-center">Reversed?</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($challans as $ch)
                            <tr class="{{ $ch->is_reversed ? 'table-danger' : '' }}">
                                <td>
                                    <a href="{{ route('admin.sales-challans.show', $ch) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $ch->challan_code }}
                                    </a>
                                </td>
                                <td class="text-nowrap small">
                                    {{ \Carbon\Carbon::parse($ch->challan_date)->format('d M Y') }}
                                </td>
                                <td>
                                    @if ($ch->salesInvoice)
                                        <a href="{{ route('admin.sales-invoices.show', $ch->salesInvoice) }}"
                                           class="text-decoration-none">
                                            {{ $ch->salesInvoice->invoice_code }}
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($ch->salesInvoice && $ch->salesInvoice->customer)
                                        <span class="fw-semibold">{{ $ch->salesInvoice->customer->customer_name }}</span>
                                        <div class="small text-muted">{{ $ch->salesInvoice->customer->customer_code }}</div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($ch->branch)
                                        {{ $ch->branch->branch_name }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end fw-semibold">
                                    Tk {{ number_format((float) $ch->issue_cost, 2) }}
                                </td>
                                <td class="small">
                                    @if (!empty($ch->transport_name) || !empty($ch->vehicle_number))
                                        @if (!empty($ch->transport_name))
                                            <i class="fas fa-truck-front me-1 text-muted"></i>{{ $ch->transport_name }}
                                        @endif
                                        @if (!empty($ch->vehicle_number))
                                            <div class="text-muted">
                                                <i class="fas fa-car me-1"></i>{{ $ch->vehicle_number }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($ch->is_reversed)
                                        <span class="badge bg-danger-subtle text-danger">
                                            <i class="fas fa-rotate-left me-1"></i>Reversed
                                        </span>
                                    @else
                                        <span class="badge bg-success-subtle text-success">
                                            <i class="fas fa-circle-check me-1"></i>Active
                                        </span>
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.sales-challans.show', $ch) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No issued challans found. Try adjusting filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-2">
                {{ $challans->links() }}
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    // DataTables only on the issued-challans table (large history).
    $('#dataTable').DataTable({
        paging: false,
        info: false,
        ordering: true,
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter issued:', emptyTable: 'No issued challans on this page.' }
    });

    // Tab persistence: when user clicks a tab, update the hidden ?tab= input
    // and the URL so refresh keeps them on the same tab.
    window.switchTab = function (tabName) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url.toString());
        // Also update the hidden input in the filter form so a filter submit
        // preserves the active tab.
        $('input[name="tab"]').val(tabName);
    };

    // CSV export: pass current filter params to export URL
    $('#csvExportBtn').on('click', function(e) {
        e.preventDefault();
        const params = new URLSearchParams();
        const fields = ['from_date', 'to_date', 'branch_id', 'search'];
        fields.forEach(f => {
            const val = $(`[name="${f}"]`).val();
            if (val && val !== '') params.set(f, val);
        });
        window.open($(this).attr('href') + '?' + params.toString(), '_blank');
    });
});
</script>
@endpush
@endsection
