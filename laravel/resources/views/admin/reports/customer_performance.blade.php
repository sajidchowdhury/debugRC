@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
         style="background: linear-gradient(135deg,#0e7490,#059669);">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-users me-2"></i>Customer Performance</h2>
            <p class="mb-0 small opacity-75">
                360° customer analytics — CLV, churn risk, segmentation, revenue distribution.
                {{ \Carbon\Carbon::parse($meta['from_date'])->format('d M Y') }} → {{ \Carbon\Carbon::parse($meta['to_date'])->format('d M Y') }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
            <a href="{{ route('dashboard') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-tachometer-alt me-1"></i> Dashboard
            </a>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.customerPerformance') }}" class="row g-2 align-items-end">
                <div class="col-sm-4 col-md-2">
                    <label class="form-label small mb-1">From date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="{{ old('from_date', request('from_date', $meta['from_date'] ?? '')) }}">
                </div>
                <div class="col-sm-4 col-md-2">
                    <label class="form-label small mb-1">To date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="{{ old('to_date', request('to_date', $meta['to_date'] ?? '')) }}">
                </div>
                <div class="col-sm-4 col-md-2">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All Branches</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" {{ $selectedBranch == $b->id ? 'selected' : '' }}>{{ $b->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-4 col-md-2">
                    <label class="form-label small mb-1">Salesman</label>
                    <select name="salesman_id" class="form-select form-select-sm">
                        <option value="">All Salesmen</option>
                        @foreach ($salesmen as $s)
                            <option value="{{ $s->id }}" {{ $selectedSalesman == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- KPI cards --}}
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3 text-center">
                    <div class="text-muted small">Active Customers</div>
                    <div class="h4 mb-0 fw-bold text-primary">{{ number_format($kpis['active_customers']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3 text-center">
                    <div class="text-muted small">Avg CLV (3yr)</div>
                    <div class="h4 mb-0 fw-bold text-success">{{ number_format($kpis['avg_clv'], 0) }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3 text-center">
                    <div class="text-muted small">Churn Rate</div>
                    <div class="h4 mb-0 fw-bold {{ $kpis['overall_churn'] > 30 ? 'text-danger' : 'text-warning' }}">{{ $kpis['overall_churn'] }}%</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3 text-center">
                    <div class="text-muted small">Repeat Rate</div>
                    <div class="h4 mb-0 fw-bold text-info">{{ $kpis['repeat_rate'] }}%</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3 text-center">
                    <div class="text-muted small">AOV</div>
                    <div class="h4 mb-0 fw-bold">{{ number_format($kpis['aov'], 0) }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3 text-center">
                    <div class="text-muted small">Retention</div>
                    <div class="h4 mb-0 fw-bold {{ $kpis['retention_rate'] >= 80 ? 'text-success' : 'text-warning' }}">{{ $kpis['retention_rate'] }}%</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Charts row 1: Segmentation + Churn --}}
    <div class="row g-2 mb-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 px-3 border-bottom-0">
                    <span class="fw-semibold small"><i class="fas fa-chart-pie me-1 text-info"></i> Customer Segmentation</span>
                </div>
                <div class="card-body p-2">
                    <canvas id="segmentationChart" height="220"></canvas>
                    {{-- Segment count table --}}
                    <table class="table table-sm table-borderless mb-0 mt-1">
                        @foreach ($segmentation['counts'] as $seg => $cnt)
                            @php
                                $colors = ['High Value' => '#10b981', 'Loyal' => '#3b82f6', 'At Risk' => '#f59e0b', 'New' => '#8b5cf6'];
                                $rev = $segmentation['revenue'][$seg] ?? 0;
                            @endphp
                            <tr>
                                <td><span class="badge" style="background:{{ $colors[$seg] }}">{{ $seg }}</span></td>
                                <td class="text-center">{{ $cnt }} customers</td>
                                <td class="text-end fw-semibold">{{ number_format($rev, 0) }} Tk</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 px-3 border-bottom-0">
                    <span class="fw-semibold small"><i class="fas fa-exclamation-triangle me-1 text-warning"></i> Churn Distribution</span>
                </div>
                <div class="card-body p-2">
                    <canvas id="churnChart" height="220"></canvas>
                    <table class="table table-sm table-borderless mb-0 mt-1">
                        @foreach ($churnDist as $cat => $cnt)
                            @php
                                $colors = ['Low' => '#10b981', 'Medium' => '#f59e0b', 'High' => '#ef4444'];
                                $descs = ['Low' => 'Last order < 30 days', 'Medium' => 'Last order 31-90 days', 'High' => 'Last order > 90 days'];
                            @endphp
                            <tr>
                                <td><span class="badge" style="background:{{ $colors[$cat] }}">{{ $cat }}</span></td>
                                <td class="text-muted small">{{ $descs[$cat] }}</td>
                                <td class="text-center fw-semibold">{{ $cnt }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Charts row 2: CLV trend + Revenue by segment --}}
    <div class="row g-2 mb-3">
        <div class="col-md-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 px-3 border-bottom-0">
                    <span class="fw-semibold small"><i class="fas fa-chart-line me-1 text-success"></i> CLV Trend (6 Months)</span>
                </div>
                <div class="card-body p-2">
                    <canvas id="clvTrendChart" height="200"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 px-3 border-bottom-0">
                    <span class="fw-semibold small"><i class="fas fa-chart-bar me-1 text-primary"></i> Revenue by Segment</span>
                </div>
                <div class="card-body p-2">
                    <canvas id="revenueSegmentChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Top customers table --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-2 px-3">
            <span class="fw-semibold small"><i class="fas fa-trophy me-1 text-warning"></i> Top {{ count($topCustomers) }} Customers by Revenue</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th width="30">#</th>
                            <th>Customer</th>
                            <th class="text-center">Invoices</th>
                            <th class="text-end">Period Revenue</th>
                            <th class="text-end">AOV</th>
                            <th class="text-end">CLV (3yr)</th>
                            <th class="text-center">Churn</th>
                            <th>Last Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($topCustomers as $idx => $r)
                            <tr>
                                <td>
                                    @if ($idx < 3)
                                        <span class="badge bg-warning text-dark"><i class="fas fa-trophy me-1"></i>{{ $idx + 1 }}</span>
                                    @else
                                        <span class="text-muted">{{ $idx + 1 }}</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('admin.customers.show', $r['id']) }}" class="text-decoration-none">
                                        <code>{{ $r['code'] }}</code><br>
                                        <span class="fw-semibold">{{ $r['name'] }}</span>
                                    </a>
                                </td>
                                <td class="text-center">{{ number_format($r['invoices']) }}</td>
                                <td class="text-end fw-semibold">{{ number_format($r['revenue'], 2) }}</td>
                                <td class="text-end">{{ number_format($r['aov'], 0) }}</td>
                                <td class="text-end fw-semibold text-success">{{ number_format($r['clv'], 0) }}</td>
                                <td class="text-center">
                                    @php
                                        $churnColors = ['Low' => 'success', 'Medium' => 'warning', 'High' => 'danger'];
                                    @endphp
                                    <span class="badge bg-{{ $churnColors[$r['churn_risk']] ?? 'secondary' }}">{{ $r['churn_risk'] }}</span>
                                </td>
                                <td>{{ $r['last_order'] ? \Carbon\Carbon::parse($r['last_order'])->format('d M Y') : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-muted text-center py-3">No customer activity in the period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Full customer table with CLV + churn + segment --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-2 px-3 d-flex justify-content-between">
            <span class="fw-semibold small"><i class="fas fa-table me-1"></i> All Customers (Top 100)</span>
            <span class="text-muted small">CLV = AOV × Frequency × 3</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="custPerfTable" class="table table-sm table-striped table-hover mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th width="30">#</th>
                            <th>Customer</th>
                            <th>Segment</th>
                            <th class="text-center">Inv.</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Due</th>
                            <th class="text-end">AOV</th>
                            <th class="text-end">CLV</th>
                            <th class="text-center">Churn</th>
                            <th>Last Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customerTable as $idx => $r)
                            <tr>
                                <td>{{ $idx + 1 }}</td>
                                <td>
                                    <a href="{{ route('admin.customers.show', $r['id']) }}" class="text-decoration-none">
                                        <code>{{ $r['code'] }}</code>
                                        <span class="fw-semibold ms-1">{{ $r['name'] }}</span>
                                    </a>
                                </td>
                                <td>
                                    @php
                                        $segColors = ['High Value' => 'success', 'Loyal' => 'primary', 'At Risk' => 'warning', 'New' => 'info'];
                                    @endphp
                                    <span class="badge bg-{{ $segColors[$r['segment']] ?? 'secondary' }}">{{ $r['segment'] }}</span>
                                </td>
                                <td class="text-center">{{ $r['invoices'] }}</td>
                                <td class="text-end fw-semibold">{{ number_format($r['revenue'], 2) }}</td>
                                <td class="text-end {{ $r['due'] > 0 ? 'text-danger' : '' }}">{{ number_format($r['due'], 2) }}</td>
                                <td class="text-end">{{ number_format($r['aov'], 0) }}</td>
                                <td class="text-end text-success fw-semibold">{{ number_format($r['clv'], 0) }}</td>
                                <td class="text-center">
                                    <span class="badge bg-{{ $churnColors[$r['churn_cat']] ?? 'secondary' }}">{{ $r['churn_cat'] }}</span>
                                </td>
                                <td>{{ $r['last_order'] ? \Carbon\Carbon::parse($r['last_order'])->diffForHumans() : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-muted text-center py-3">No customer activity in the period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {

    const segColors = {
        'High Value': '#10b981',
        'Loyal': '#3b82f6',
        'At Risk': '#f59e0b',
        'New': '#8b5cf6'
    };

    const churnColors = {
        'Low': '#10b981',
        'Medium': '#f59e0b',
        'High': '#ef4444'
    };

    {{-- Segmentation donut --}}
    new Chart(document.getElementById('segmentationChart'), {
        type: 'doughnut',
        data: {
            labels: Object.keys({{ Illuminate\Support\Js::from($segmentation['counts']) }}),
            datasets: [{
                data: Object.values({{ Illuminate\Support\Js::from($segmentation['counts']) }}),
                backgroundColor: Object.keys({{ Illuminate\Support\Js::from($segmentation['counts']) }}).map(k => segColors[k] || '#6c757d'),
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 12, padding: 8 } } }
        }
    });

    {{-- Churn distribution donut --}}
    new Chart(document.getElementById('churnChart'), {
        type: 'doughnut',
        data: {
            labels: Object.keys({{{ Illuminate\Support\Js::from($churnDist) }}}),
            datasets: [{
                data: Object.values({{{ Illuminate\Support\Js::from($churnDist) }}}),
                backgroundColor: Object.keys({{{ Illuminate\Support\Js::from($churnDist) }}}).map(k => churnColors[k] || '#6c757d'),
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 12, padding: 8 } } }
        }
    });

    {{-- CLV Trend line --}}
    const clvTrend = {{ Illuminate\Support\Js::from($clvTrend) }};
    if (clvTrend.length) {
        new Chart(document.getElementById('clvTrendChart'), {
            type: 'line',
            data: {
                labels: clvTrend.map(r => r.month),
                datasets: [{
                    label: 'Avg CLV (3yr)',
                    data: clvTrend.map(r => r.avg_clv),
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.1)',
                    fill: true,
                    tension: 0.3,
                    yAxisID: 'y'
                }, {
                    label: 'AOV',
                    data: clvTrend.map(r => r.aov),
                    borderColor: '#3b82f6',
                    backgroundColor: 'transparent',
                    borderDash: [5,5],
                    tension: 0.3,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                scales: {
                    y:  { position: 'left',  title: { display: true, text: 'CLV' } },
                    y1: { position: 'right', title: { display: true, text: 'AOV' }, grid: { drawOnChartArea: false } }
                },
                plugins: { legend: { labels: { boxWidth: 12 } } }
            }
        });
    }

    {{-- Revenue by segment horizontal bar --}}
    const revSeg = {{ Illuminate\Support\Js::from($revenueBySegment) }};
    new Chart(document.getElementById('revenueSegmentChart'), {
        type: 'bar',
        data: {
            labels: Object.keys(revSeg),
            datasets: [{
                label: 'Revenue',
                data: Object.values(revSeg),
                backgroundColor: Object.keys(revSeg).map(k => segColors[k] || '#6c757d'),
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true } }
        }
    });

    {{-- DataTables for full customer table --}}
    $('#custPerfTable').DataTable({
        paging: false,
        searching: true,
        ordering: true,
        info: false,
        order: [[4, 'desc']]
    });
});
</script>
@endpush
