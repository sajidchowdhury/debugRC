@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
         style="background: linear-gradient(135deg,#1e3a8a,#7c3aed);">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-filter me-2"></i>Sales Funnel / Pipeline</h2>
            <p class="mb-0 small opacity-75">
                Pipeline stages from draft → godown → delivered → paid.
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
            <form method="GET" action="{{ route('admin.reports.salesFunnel') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">From date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="{{ old('from_date', request('from_date', $meta['from_date'] ?? '')) }}">
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">To date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="{{ old('to_date', request('to_date', $meta['to_date'] ?? '')) }}">
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" {{ $selectedBranch == $b->id ? 'selected' : '' }}>
                                {{ $b->branch_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ========== KPI CARDS ========== --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Open Pipeline</div>
                    <div class="h4 mb-0 mt-1 fw-bold text-primary">Tk {{ number_format($kpis['open_pipeline'], 0) }}</div>
                    <div class="small text-muted">{{ $kpis['open_count'] }} deal{{ $kpis['open_count'] !== 1 ? 's' : '' }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Weighted Pipeline</div>
                    <div class="h4 mb-0 mt-1 fw-bold text-success">Tk {{ number_format($kpis['weighted_pipeline'], 0) }}</div>
                    <div class="small text-muted">Probability-adjusted</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Closed Won</div>
                    <div class="h4 mb-0 mt-1 fw-bold">Tk {{ number_format($kpis['closed_won'], 0) }}</div>
                    <div class="small text-muted">{{ $kpis['closed_won_count'] }} deal{{ $kpis['closed_won_count'] !== 1 ? 's' : '' }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Win Rate</div>
                    <div class="h4 mb-0 mt-1 fw-bold {{ $kpis['win_rate'] >= 60 ? 'text-success' : ($kpis['win_rate'] >= 30 ? 'text-warning' : 'text-danger') }}">
                        {{ $kpis['win_rate'] }}%
                    </div>
                    <div class="small text-muted">Draft → Paid</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Pipeline Velocity</div>
                    <div class="h4 mb-0 mt-1 fw-bold text-warning">{{ $kpis['velocity_days'] }} days</div>
                    <div class="small text-muted">Avg draft → delivered</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Stale Drafts</div>
                    <div class="h4 mb-0 mt-1 fw-bold {{ $kpis['stale_drafts'] > 0 ? 'text-danger' : 'text-success' }}">
                        {{ $kpis['stale_drafts'] }}
                    </div>
                    <div class="small text-muted">{{ $kpis['stale_drafts'] > 0 ? '>7 days old' : 'No stale drafts' }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ========== CHARTS ROW 1 ========== --}}
    <div class="row g-3 mb-4">
        {{-- Funnel Chart (8 cols) --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-filter me-1 text-primary"></i> Sales Funnel (Value by Stage)</h3>
                </div>
                <div class="card-body">
                    <canvas id="funnelChart" height="260"></canvas>
                </div>
            </div>
        </div>
        {{-- Conversion Rates + Forecast (4 cols) --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 mb-3">
                <div class="card-header bg-white py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-arrows-turn-right me-1 text-success"></i> Stage Conversion Rates</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>From → To</th><th class="text-end">Rate</th><th class="text-end">Count</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($conversionRates as $cr)
                                <tr>
                                    <td class="small">{{ $cr['from'] }} → {{ $cr['to'] }}</td>
                                    <td class="text-end fw-semibold {{ $cr['rate'] >= 60 ? 'text-success' : ($cr['rate'] >= 30 ? 'text-warning' : 'text-danger') }}">
                                        {{ $cr['rate'] }}%
                                    </td>
                                    <td class="text-end small text-muted">{{ $cr['from_count'] }} → {{ $cr['to_count'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted py-2">No data.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-chart-line me-1 text-indigo"></i> Revenue Forecast</h3>
                </div>
                <div class="card-body">
                    <canvas id="forecastChart" height="130"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- ========== CHARTS ROW 2 ========== --}}
    <div class="row g-3 mb-4">
        {{-- Pipeline Trend (6 cols) --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-chart-area me-1 text-primary"></i> Pipeline Trend (6 Months)</h3>
                </div>
                <div class="card-body">
                    <canvas id="pipelineTrendChart" height="200"></canvas>
                </div>
            </div>
        </div>
        {{-- Salesman Performance (6 cols) --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-user-tie me-1 text-warning"></i> Salesman Pipeline Ownership</h3>
                </div>
                <div class="card-body">
                    <canvas id="salesmanChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- ========== FUNNEL STAGE TABLE ========== --}}
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-list-check me-1 text-primary"></i> Pipeline Stage Summary</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Stage</th>
                                <th class="text-end">Count</th>
                                <th class="text-end">Total Value</th>
                                <th class="text-end">Due</th>
                                <th>Probability</th>
                                <th class="text-end">Weighted Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $totalValue = 0; $totalWeighted = 0; $totalCount = 0; @endphp
                            @forelse ($funnelData as $key => $stage)
                                @php
                                    $totalValue += $stage['value'];
                                    $totalWeighted += $stage['weighted'];
                                    $totalCount += $stage['count'];
                                @endphp
                                <tr>
                                    <td>
                                        <span class="badge" style="background:{{ $stage['color'] }};color:#fff;">
                                            {{ $stage['label'] }}
                                        </span>
                                    </td>
                                    <td class="text-end fw-semibold">{{ number_format($stage['count']) }}</td>
                                    <td class="text-end">Tk {{ number_format($stage['value'], 0) }}</td>
                                    <td class="text-end {{ $stage['due'] > 0 ? 'text-danger' : '' }}">Tk {{ number_format($stage['due'], 0) }}</td>
                                    <td>{{ ($stage['probability'] * 100) }}%</td>
                                    <td class="text-end fw-semibold text-success">Tk {{ number_format($stage['weighted'], 0) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-3">No pipeline data for this period.</td></tr>
                            @endforelse
                            @if (!empty($funnelData))
                                <tr class="table-light fw-bold">
                                    <td>Total</td>
                                    <td class="text-end">{{ number_format($totalCount) }}</td>
                                    <td class="text-end">Tk {{ number_format($totalValue, 0) }}</td>
                                    <td></td>
                                    <td></td>
                                    <td class="text-end text-success">Tk {{ number_format($totalWeighted, 0) }}</td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ========== OPEN OPPORTUNITIES TABLE ========== --}}
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-door-open me-1 text-warning"></i> Open Opportunities (Top 25)</h3>
                    <a href="{{ route('admin.sales-invoices.index') }}" class="btn btn-sm btn-outline-secondary">All Invoices</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Invoice</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Salesman</th>
                                    <th>Branch</th>
                                    <th>Stage</th>
                                    <th class="text-end">Amount</th>
                                    <th class="text-end">Due</th>
                                    <th class="text-end">Days Open</th>
                                    <th>Health</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($openOpportunities as $opp)
                                    <tr>
                                        <td><code><a href="{{ route('admin.sales-invoices.show', $opp['id']) }}" class="text-decoration-none">{{ $opp['code'] }}</a></code></td>
                                        <td class="small">{{ \Carbon\Carbon::parse($opp['date'])->format('d M') }}</td>
                                        <td>{{ $opp['customer'] }}</td>
                                        <td class="small">{{ $opp['salesman'] }}</td>
                                        <td class="small">{{ $opp['branch'] }}</td>
                                        <td>
                                            @php
                                                $stageColors = ['Draft' => 'secondary', 'Godown' => 'warning', 'Delivered' => 'info'];
                                            @endphp
                                            <span class="badge bg-{{ $stageColors[$opp['stage']] ?? 'primary' }}-subtle text-{{ $stageColors[$opp['stage']] ?? 'primary' }}">
                                                {{ $opp['stage'] }}
                                            </span>
                                        </td>
                                        <td class="text-end fw-semibold">Tk {{ number_format($opp['amount'], 0) }}</td>
                                        <td class="text-end {{ $opp['due'] > 0 ? 'text-danger' : '' }}">Tk {{ number_format($opp['due'], 0) }}</td>
                                        <td class="text-end {{ $opp['days_open'] > 14 ? 'text-danger fw-bold' : '' }}">
                                            {{ $opp['days_open'] }}d
                                        </td>
                                        <td>
                                            @if ($opp['days_open'] <= 3)
                                                <span class="badge bg-success-subtle text-success">Fresh</span>
                                            @elseif ($opp['days_open'] <= 7)
                                                <span class="badge bg-warning-subtle text-warning">Aging</span>
                                            @else
                                                <span class="badge bg-danger-subtle text-danger">Stale</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="10" class="text-center text-muted py-3">No open opportunities.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ========== SALESMAN TABLE ========== --}}
    @if (!empty($salesmanPerformance))
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-ranking-star me-1 text-primary"></i> Salesman Leaderboard</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Salesman</th>
                                <th class="text-end">Open Value</th>
                                <th class="text-end">Closed Value</th>
                                <th class="text-end">Total Value</th>
                                <th class="text-end">Win Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($salesmanPerformance as $i => $sp)
                                <tr>
                                    <td>{{ $i + 1 }}</td>
                                    <td class="fw-semibold">{{ $sp['name'] }}</td>
                                    <td class="text-end">Tk {{ number_format($sp['open_value'], 0) }} <span class="text-muted">({{ $sp['open_count'] }})</span></td>
                                    <td class="text-end text-success">Tk {{ number_format($sp['closed_value'], 0) }} <span class="text-muted">({{ $sp['closed_count'] }})</span></td>
                                    <td class="text-end fw-semibold">Tk {{ number_format($sp['total_value'], 0) }}</td>
                                    <td class="text-end">
                                        <span class="badge {{ $sp['win_rate'] >= 60 ? 'bg-success-subtle text-success' : ($sp['win_rate'] >= 30 ? 'bg-warning-subtle text-warning' : 'bg-danger-subtle text-danger') }}">
                                            {{ $sp['win_rate'] }}%
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>
@endsection

@push('scripts')
<script src="/assets/js/bootstrep/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const alpha = (hex, a) => {
        const r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
        return `rgba(${r},${g},${b},${a})`;
    };

    // ============================================================
    // 1. Sales Funnel Horizontal Bar Chart
    // ============================================================
    const funnelData = @json($funnelData);
    const funnelLabels = Object.values(funnelData).map(d => d.label);
    const funnelValues = Object.values(funnelData).map(d => d.value);
    const funnelCounts = Object.values(funnelData).map(d => d.count);
    const funnelColors = Object.values(funnelData).map(d => d.color);

    new Chart(document.getElementById('funnelChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: funnelLabels,
            datasets: [
                {
                    label: 'Total Value (Tk)',
                    data: funnelValues,
                    backgroundColor: funnelColors.map(c => alpha(c, 0.75)),
                    borderColor: funnelColors,
                    borderWidth: 1.5,
                    borderRadius: 6,
                },
                {
                    label: 'Weighted Value (Tk)',
                    data: Object.values(funnelData).map(d => d.weighted),
                    backgroundColor: funnelColors.map(c => alpha(c, 0.35)),
                    borderColor: funnelColors.map(c => alpha(c, 0.6)),
                    borderWidth: 1,
                    borderRadius: 4,
                    borderDash: [4,2],
                }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } },
                tooltip: {
                    callbacks: {
                        afterLabel: function(ctx) {
                            return 'Invoices: ' + funnelCounts[ctx.dataIndex];
                        }
                    }
                }
            },
            scales: {
                x: { ticks: { callback: v => 'Tk ' + (v/1000).toFixed(0) + 'k' }, grid: { color: alpha('#64748b', 0.08) } },
                y: { grid: { display: false } }
            }
        }
    });

    // ============================================================
    // 2. Forecast Chart
    // ============================================================
    const forecast = @json($forecast);
    new Chart(document.getElementById('forecastChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: ['30 Days', '60 Days', '90 Days'],
            datasets: [{
                label: 'Expected Revenue',
                data: [forecast['30_days'], forecast['60_days'], forecast['90_days']],
                backgroundColor: [alpha('#7c3aed', 0.7), alpha('#6366f1', 0.7), alpha('#4f46e5', 0.7)],
                borderColor: ['#7c3aed', '#6366f1', '#4f46e5'],
                borderWidth: 1.5,
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => 'Tk ' + ctx.parsed.y.toLocaleString() } }
            },
            scales: {
                y: { ticks: { callback: v => 'Tk ' + (v/1000).toFixed(0) + 'k' }, grid: { color: alpha('#64748b', 0.08) } },
                x: { grid: { display: false } }
            }
        }
    });

    // ============================================================
    // 3. Pipeline Trend Line Chart
    // ============================================================
    const trendData = @json($pipelineTrend);
    new Chart(document.getElementById('pipelineTrendChart').getContext('2d'), {
        type: 'line',
        data: {
            labels: trendData.map(d => d.month),
            datasets: [
                {
                    label: 'Open Pipeline',
                    data: trendData.map(d => d.open_pipeline),
                    borderColor: '#7c3aed',
                    backgroundColor: alpha('#7c3aed', 0.12),
                    fill: true,
                    tension: 0.35,
                    pointRadius: 4,
                    borderWidth: 2.5,
                },
                {
                    label: 'Closed Won',
                    data: trendData.map(d => d.closed_won),
                    borderColor: '#16a34a',
                    backgroundColor: alpha('#16a34a', 0.08),
                    fill: true,
                    tension: 0.35,
                    pointRadius: 4,
                    borderWidth: 2.5,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } },
                tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': Tk ' + ctx.parsed.y.toLocaleString() } }
            },
            scales: {
                y: { ticks: { callback: v => 'Tk ' + (v/1000).toFixed(0) + 'k' }, grid: { color: alpha('#64748b', 0.08) } },
                x: { grid: { display: false } }
            }
        }
    });

    // ============================================================
    // 4. Salesman Stacked Bar Chart
    // ============================================================
    const salesmanData = @json($salesmanPerformance);
    if (salesmanData.length > 0) {
        new Chart(document.getElementById('salesmanChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: salesmanData.map(d => d.name),
                datasets: [
                    {
                        label: 'Open Value',
                        data: salesmanData.map(d => d.open_value),
                        backgroundColor: alpha('#f59e0b', 0.7),
                        borderColor: '#f59e0b',
                        borderWidth: 1,
                        borderRadius: 4,
                    },
                    {
                        label: 'Closed Won',
                        data: salesmanData.map(d => d.closed_value),
                        backgroundColor: alpha('#16a34a', 0.7),
                        borderColor: '#16a34a',
                        borderWidth: 1,
                        borderRadius: 4,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': Tk ' + ctx.parsed.y.toLocaleString() } }
                },
                scales: {
                    y: { stacked: true, ticks: { callback: v => 'Tk ' + (v/1000).toFixed(0) + 'k' }, grid: { color: alpha('#64748b', 0.08) } },
                    x: { stacked: true, grid: { display: false } }
                }
            }
        });
    }
});
</script>
@endpush
