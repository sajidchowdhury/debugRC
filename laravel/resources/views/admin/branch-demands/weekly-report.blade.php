@extends('layouts.admin')

@section('content')
@php
    $fmt = function ($val, $decimals = 2) {
        if (!is_numeric($val)) return $val;
        return number_format((float) $val, $decimals);
    };

    $zeroClass = function ($val) {
        if (!is_numeric($val)) return '';
        return (float) $val == 0 ? 'text-muted' : '';
    };

    $columnLabels = [
        'cash_sale'           => 'Cash Sale',
        'collection_cash'     => 'Collection (Cash)',
        'collection_bank'     => 'Collection (Bank)',
        'expenses'            => 'Expenses',
        'money_transfer_ho'   => 'Money Transfer by HO',
        'warehouse_wise_sale' => 'Warehouse-Wise Sale',
        'demand_bill'         => 'Demand Bill',
        'price_add'           => 'Price (Add)',
        'price_less'          => 'Price (Less)',
        'profit'              => 'Profit',
        'discount'            => 'Discount',
        'sales_return'        => 'Sales Return',
        'product_transfer'    => 'Product Transfer',
        'missing_bank_amount' => 'Missing Bank Amt',
        'ho_bill_bf'          => 'HO Bill (BF)',
        'ho_total_bill'       => 'HO Total Bill',
        'cash_in_hand'        => 'Cash In Hand',
        'warehouse_stock_value' => 'Stock Value',
        'customer_due'        => 'Customer Due',
        'current_value'       => 'Current Value',
        'gap'                 => 'GAP',
    ];

    // Drill-down enabled columns
    $drillable = ['cash_sale', 'collection_cash', 'collection_bank', 'expenses',
                  'money_transfer_ho', 'demand_bill', 'sales_return', 'price_add',
                  'price_less', 'discount', 'product_transfer'];
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0d9488,#059669);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-chart-line me-2"></i>Branch Demand — Weekly Audit Report</h1>
            <p class="mb-0 small opacity-75">
                {{ $report['meta']['branch_name'] ?? 'Branch' }} &bull;
                {{ \Carbon\Carbon::parse($report['meta']['from_date'])->format('d M Y') }} &rarr;
                {{ \Carbon\Carbon::parse($report['meta']['to_date'])->format('d M Y') }}
                &bull; {{ $report['meta']['days_count'] }} day(s)
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.branch-demands.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-list me-1"></i> Demands
            </a>
            <a href="{{ route('admin.branch-demands.weekly-report.export', array_filter(['branch_id' => $selectedBranchId, 'from_date' => $dateFrom, 'to_date' => $dateTo])) }}"
               class="btn btn-outline-light btn-sm">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </a>
        </div>
    </header>

    {{-- Filter form --}}
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.branch-demands.weekly-report') }}" class="row g-2 align-items-end">
                @if(count($branches) > 0)
                <div class="col-auto">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" {{ $selectedBranchId == $branch->id ? 'selected' : '' }}>
                            {{ $branch->branch_name }}
                        </option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="col-auto">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" name="from_date" value="{{ $dateFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">To</label>
                    <input type="date" name="to_date" value="{{ $dateTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-play me-1"></i> Run</button>
                </div>
                <div class="col-auto">
                    <a href="{{ route('admin.branch-demands.weekly-report') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Main report table --}}
    <div class="card shadow-sm mb-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0" id="weeklyReportTable">
                    <thead class="table-light">
                        <tr>
                            <th class="sticky-start bg-light" style="min-width:90px;">Date</th>
                            @foreach($columnLabels as $key => $label)
                            <th class="text-center" style="min-width:100px; font-size:0.75rem;">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($report['rows'] as $row)
                        <tr>
                            <td class="sticky-start bg-white fw-semibold" style="font-size:0.8rem;">
                                {{ \Carbon\Carbon::parse($row['date'])->format('d M') }}
                            </td>
                            @foreach($columnLabels as $key => $label)
                            @php
                                $val = $row[$key] ?? 0;
                                $isDrillable = in_array($key, $drillable) && is_numeric($val) && (float) $val != 0;
                            @endphp
                            <td class="text-end {{ $zeroClass($val) }}" style="font-size:0.8rem;">
                                @if($isDrillable)
                                <a href="#" class="text-decoration-none drill-link"
                                   data-column="{{ $key }}"
                                   data-branch="{{ $selectedBranchId }}"
                                   data-date="{{ $row['date'] }}"
                                   title="Click to drill down">
                                    {{ $fmt($val) }}
                                </a>
                                @else
                                    {{ $fmt($val) }}
                                @endif
                            </td>
                            @endforeach
                        </tr>
                        @empty
                        <tr>
                            <td colspan="22" class="text-center text-muted py-4">No data for the selected period.</td>
                        </tr>
                        @endforelse
                    </tbody>
                    {{-- Summary / Totals row --}}
                    @if(isset($report['summary']))
                    <tfoot class="table-warning fw-semibold">
                        <tr>
                            <td class="sticky-start bg-warning-subtle">TOTAL</td>
                            @foreach($columnLabels as $key => $label)
                            @php
                                $val = $report['summary'][$key] ?? 0;
                            @endphp
                            <td class="text-end {{ $zeroClass($val) }}" style="font-size:0.8rem;">
                                {{ $fmt($val) }}
                            </td>
                            @endforeach
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>

    {{-- Summary cards --}}
    @if(isset($report['summary']))
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0d9488;">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div>
                        <div class="small text-muted">HO Total Bill</div>
                        <div class="h5 mb-0">{{ $fmt($report['summary']['ho_total_bill']) }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#059669;">
                        <i class="fas fa-warehouse"></i>
                    </div>
                    <div>
                        <div class="small text-muted">Stock in Software</div>
                        <div class="h5 mb-0">{{ $fmt($report['summary']['warehouse_stock_value']) }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0284c7;">
                        <i class="fas fa-hand-holding-dollar"></i>
                    </div>
                    <div>
                        <div class="small text-muted">Cash In Hand</div>
                        <div class="h5 mb-0">{{ $fmt($report['summary']['cash_in_hand']) }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:{{ abs((float) ($report['summary']['gap'] ?? 0)) < 0.01 ? '#059669' : '#dc2626' }};">
                        <i class="fas fa-{{ abs((float) ($report['summary']['gap'] ?? 0)) < 0.01 ? 'check-circle' : 'exclamation-triangle' }}"></i>
                    </div>
                    <div>
                        <div class="small text-muted">GAP (Reconciliation)</div>
                        <div class="h5 mb-0 {{ abs((float) ($report['summary']['gap'] ?? 0)) < 0.01 ? 'text-success' : 'text-danger' }}">
                            {{ $fmt($report['summary']['gap']) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Drill-down modal --}}
    <div class="modal fade" id="drillDownModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="drillDownTitle">Drill Down</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="drillDownBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted">Loading transactions...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Drill-down click handler
    document.querySelectorAll('.drill-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const column = this.dataset.column;
            const branch = this.dataset.branch;
            const date = this.dataset.date;
            const columnLabels = {
                cash_sale: 'Cash Sale', collection_cash: 'Collection (Cash)',
                collection_bank: 'Collection (Bank)', expenses: 'Expenses',
                money_transfer_ho: 'Money Transfer by HO', demand_bill: 'Demand Bill',
                sales_return: 'Sales Return', price_add: 'Price (Add)',
                price_less: 'Price (Less)', discount: 'Discount',
                product_transfer: 'Product Transfer'
            };

            document.getElementById('drillDownTitle').textContent =
                (columnLabels[column] || column) + ' — ' + date;

            const body = document.getElementById('drillDownBody');
            body.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Loading...</p></div>';

            // Show modal immediately
            const modal = new bootstrap.Modal(document.getElementById('drillDownModal'));
            modal.show();

            // Fetch drill-down data
            const params = new URLSearchParams({
                column: column,
                branch_id: branch,
                date: date
            });

            fetch('{{ route("admin.branch-demands.weekly-report.drill-down") }}?' + params)
                .then(r => r.json())
                .then(data => {
                    if (data.data && data.data.length > 0) {
                        let html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">';
                        html += '<thead class="table-light"><tr>';
                        const keys = Object.keys(data.data[0]);
                        keys.forEach(k => {
                            html += '<th>' + k.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) + '</th>';
                        });
                        html += '</tr></thead><tbody>';
                        data.data.forEach(row => {
                            html += '<tr>';
                            keys.forEach(k => {
                                html += '<td>' + (row[k] !== null ? row[k] : '-') + '</td>';
                            });
                            html += '</tr>';
                        });
                        html += '</tbody></table></div>';
                        html += '<div class="mt-2 small text-muted">' + data.data.length + ' transaction(s) found.</div>';
                        body.innerHTML = html;
                    } else {
                        body.innerHTML = '<div class="text-center text-muted py-3"><i class="fas fa-info-circle me-1"></i>No underlying transactions found.</div>';
                    }
                })
                .catch(err => {
                    body.innerHTML = '<div class="alert alert-danger">Failed to load drill-down data.</div>';
                    console.error('Drill-down error:', err);
                });
        });
    });
});
</script>
@endpush
