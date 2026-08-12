@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#ea580c,#d97706);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-chart-bar me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">Period aggregates, top products, warehouse pairs, and monthly trends</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.warehouse-transfers.index') }}" class="btn btn-sm btn-light">
                <i class="fas fa-arrow-left me-1"></i>Back to Transfers
            </a>
        </div>
    </header>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form id="filter-form" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="date_from">From date</label>
                    <input type="date" id="date_from" name="date_from" class="form-control form-control-sm"
                           value="{{ now()->startOfMonth()->format('Y-m-d') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="date_to">To date</label>
                    <input type="date" id="date_to" name="date_to" class="form-control form-control-sm"
                           value="{{ now()->format('Y-m-d') }}">
                </div>
                @if (!$userBranchId)
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1" for="branch_id">Branch</label>
                        <select id="branch_id" name="branch_id" class="form-select form-select-sm">
                            <option value="">All branches</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->branch_name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-md-3 d-flex gap-2">
                    <button type="button" id="btn-run" class="btn btn-primary btn-sm" onclick="runReport()">
                        <i class="fas fa-play me-1"></i>Run Report
                    </button>
                    <button type="button" id="btn-export" class="btn btn-outline-secondary btn-sm d-none" onclick="exportCsv()">
                        <i class="fas fa-file-csv me-1"></i>Export CSV
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Loading spinner --}}
    <div id="loading" class="text-center py-5 d-none">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Running report...</span>
        </div>
        <p class="mt-2 text-muted">Generating summary report...</p>
    </div>

    {{-- Results container --}}
    <div id="results" class="d-none">
        {{-- Summary cards --}}
        <div id="summary-cards" class="row g-3 mb-4"></div>

        {{-- Branch-level aggregates --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong><i class="fas fa-building me-2"></i>Branch Aggregates</strong>
            </div>
            <div class="card-body p-0">
                <div id="branches-table" class="table-responsive"></div>
            </div>
        </div>

        {{-- Top products --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong><i class="fas fa-box me-2"></i>Top 10 Most Transferred Products</strong>
            </div>
            <div class="card-body p-0">
                <div id="top-products-table" class="table-responsive"></div>
            </div>
        </div>

        {{-- Warehouse pairs --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong><i class="fas fa-right-left me-2"></i>Most Active Warehouse Pairs</strong>
            </div>
            <div class="card-body p-0">
                <div id="warehouse-pairs-table" class="table-responsive"></div>
            </div>
        </div>

        {{-- Monthly trend --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong><i class="fas fa-calendar-alt me-2"></i>Monthly Trend</strong>
            </div>
            <div class="card-body p-0">
                <div id="monthly-trend-table" class="table-responsive"></div>
            </div>
        </div>
    </div>

    {{-- No results yet --}}
    <div id="no-results" class="text-center py-5">
        <i class="fas fa-chart-bar fa-3x text-muted mb-3"></i>
        <p class="text-muted">No report has been run yet. Set the date range and click "Run Report" above.</p>
    </div>
</div>
@endsection

@push('scripts')
<script>
let reportData = null;

async function runReport() {
    const btn = document.getElementById('btn-run');
    const loading = document.getElementById('loading');
    const results = document.getElementById('results');
    const noResults = document.getElementById('no-results');
    const btnExport = document.getElementById('btn-export');

    const dateFrom = document.getElementById('date_from').value;
    const dateTo = document.getElementById('date_to').value;
    const branchId = document.getElementById('branch_id') ? document.getElementById('branch_id').value : null;

    if (!dateFrom || !dateTo) {
        alert('Please select both From and To dates.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Running...';
    loading.classList.remove('d-none');
    results.classList.add('d-none');
    noResults.classList.add('d-none');

    const body = { date_from: dateFrom, date_to: dateTo };
    if (branchId) body.branch_id = branchId;

    try {
        const resp = await fetch('{{ route('admin.warehouse-transfers.summary-data') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify(body),
        });

        if (!resp.ok) {
            const err = await resp.json();
            throw new Error(err.error || 'Request failed');
        }

        reportData = await resp.json();
        renderResults(reportData);
        btnExport.classList.remove('d-none');
    } catch (e) {
        alert('Error: ' + e.message);
        noResults.classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-play me-1"></i>Run Report';
        loading.classList.add('d-none');
    }
}

function fmt(n, decimals = 2) {
    return Number(n).toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

function renderResults(data) {
    const results = document.getElementById('results');
    const summaryCards = document.getElementById('summary-cards');

    // Summary cards
    const avg = data.averages;
    summaryCards.innerHTML = `
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#ea580c;">
                        <i class="fas fa-list"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">${fmt(avg.total_transfers, 0)}</div>
                        <div class="text-muted small">Total Transfers</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#d97706;">
                        <i class="fas fa-taka-sign"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk ${fmt(avg.avg_value)}</div>
                        <div class="text-muted small">Avg Value / Transfer</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-boxes-stacked"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">${fmt(avg.avg_items)}</div>
                        <div class="text-muted small">Avg Items / Transfer</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#7c3aed;">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0">${data.period.from} — ${data.period.to}</div>
                        <div class="text-muted small">${data.period.branch_label}</div>
                    </div>
                </div>
            </div>
        </div>
    `;

    // Branch aggregates table
    const branchesTable = document.getElementById('branches-table');
    if (data.branches.length === 0) {
        branchesTable.innerHTML = '<div class="text-center py-4 text-muted">No transfer data for any branch in this period.</div>';
    } else {
        let rows = '';
        data.branches.forEach(b => {
            rows += `<tr>
                <td>${b.branch_name}</td>
                <td class="text-end">${fmt(b.total_transfers, 0)}</td>
                <td class="text-end"><span class="badge bg-success-subtle text-success">${fmt(b.confirmed_count, 0)}</span></td>
                <td class="text-end"><span class="badge bg-warning-subtle text-warning">${fmt(b.draft_count, 0)}</span></td>
                <td class="text-end"><span class="badge bg-secondary-subtle text-secondary">${fmt(b.cancelled_count, 0)}</span></td>
                <td class="text-end fw-semibold">Tk ${fmt(b.total_value)}</td>
            </tr>`;
        });
        branchesTable.innerHTML = `
            <table class="table table-sm table-striped table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>Branch</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Confirmed</th>
                    <th class="text-end">Draft</th>
                    <th class="text-end">Cancelled</th>
                    <th class="text-end">Value (Tk)</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    // Top products table
    const topProductsTable = document.getElementById('top-products-table');
    if (data.top_products.length === 0) {
        topProductsTable.innerHTML = '<div class="text-center py-4 text-muted">No product transfer data in this period.</div>';
    } else {
        let rows = '';
        data.top_products.forEach((p, i) => {
            rows += `<tr>
                <td class="text-center text-muted small">${i + 1}</td>
                <td><span class="fw-semibold">${p.product_name}</span> <span class="small text-muted">(${p.product_code})</span></td>
                <td class="text-end">${fmt(p.total_qty)}</td>
                <td class="text-end fw-semibold">Tk ${fmt(p.total_value)}</td>
                <td class="text-end">${fmt(p.transfer_count, 0)}</td>
            </tr>`;
        });
        topProductsTable.innerHTML = `
            <table class="table table-sm table-striped table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th style="width:30px">#</th>
                    <th>Product</th>
                    <th class="text-end">Total Qty</th>
                    <th class="text-end">Total Value (Tk)</th>
                    <th class="text-end">Transfers</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    // Warehouse pairs table
    const pairsTable = document.getElementById('warehouse-pairs-table');
    if (data.warehouse_pairs.length === 0) {
        pairsTable.innerHTML = '<div class="text-center py-4 text-muted">No warehouse pair data in this period.</div>';
    } else {
        let rows = '';
        data.warehouse_pairs.forEach((wp, i) => {
            rows += `<tr>
                <td class="text-center text-muted small">${i + 1}</td>
                <td><span class="fw-semibold">${wp.from_warehouse_name}</span></td>
                <td><span class="fw-semibold">${wp.to_warehouse_name}</span></td>
                <td class="text-end">${fmt(wp.transfer_count, 0)}</td>
                <td class="text-end fw-semibold">Tk ${fmt(wp.total_value)}</td>
            </tr>`;
        });
        pairsTable.innerHTML = `
            <table class="table table-sm table-striped table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th style="width:30px">#</th>
                    <th>From Warehouse</th>
                    <th>To Warehouse</th>
                    <th class="text-end">Transfers</th>
                    <th class="text-end">Total Value (Tk)</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    // Monthly trend table
    const trendTable = document.getElementById('monthly-trend-table');
    if (data.monthly_trend.length === 0) {
        trendTable.innerHTML = '<div class="text-center py-4 text-muted">No monthly trend data in this period.</div>';
    } else {
        let rows = '';
        data.monthly_trend.forEach(m => {
            rows += `<tr>
                <td class="text-nowrap fw-semibold">${m.month}</td>
                <td class="text-end">${fmt(m.transfer_count, 0)}</td>
                <td class="text-end"><span class="badge bg-success-subtle text-success">${fmt(m.confirmed_count, 0)}</span></td>
                <td class="text-end"><span class="badge bg-warning-subtle text-warning">${fmt(m.draft_count, 0)}</span></td>
                <td class="text-end"><span class="badge bg-secondary-subtle text-secondary">${fmt(m.cancelled_count, 0)}</span></td>
                <td class="text-end fw-semibold">Tk ${fmt(m.total_value)}</td>
            </tr>`;
        });
        trendTable.innerHTML = `
            <table class="table table-sm table-striped table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>Month</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Confirmed</th>
                    <th class="text-end">Draft</th>
                    <th class="text-end">Cancelled</th>
                    <th class="text-end">Value (Tk)</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    results.classList.remove('d-none');
}

function exportCsv() {
    // REPORTS-AUDIT-6 (G-241 / csv-export.md G26): the CSV export is now
    // server-side (admin.warehouse-transfers.summary.export route). The
    // previous client-side JS CSV builder produced unescaped output (no
    // RFC 4180 quoting for cells containing commas / double-quotes /
    // newlines), no BOM (Excel mojibake on Bengali branch/warehouse
    // names), and bypassed the export_audit_log trail. The server-side
    // route fixes all three: CsvExporter::exportFromRows handles RFC
    // 4180 + BOM + audit log. We simply redirect the browser to the
    // route with the current filter params — the server streams the CSV
    // back as a download.
    const from = document.getElementById('date_from').value;
    const to   = document.getElementById('date_to').value;
    if (!from || !to) {
        alert('Please select a date range before exporting.');
        return;
    }

    const params = new URLSearchParams();
    params.set('date_from', from);
    params.set('date_to', to);

    const branchSelect = document.getElementById('branch_id');
    if (branchSelect && branchSelect.value) {
        params.set('branch_id', branchSelect.value);
    }

    window.location.href = '{{ route('admin.warehouse-transfers.summary.export') }}?' + params.toString();
}
</script>
@endpush
