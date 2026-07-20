@extends('layouts.admin')

@section('content')
<div class="py-3">
    {{-- Welcome header --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
         style="background: linear-gradient(135deg,#0f172a,#1e3a8a);">
        <div>
            <h2 class="h4 mb-1">
                <i class="fas fa-tachometer-alt me-2"></i>Revenue Overview Dashboard
            </h2>
            <p class="mb-0 small opacity-75">
                Welcome back, <strong>{{ session('employee_name', Auth::user()->username) }}</strong>
                — {{ session('role') }} @if (session('branch_name')) · {{ session('branch_name') }} @endif
                · MTD: {{ now()->format('M Y') }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <div class="btn-group btn-group-sm" id="trendToggle">
                <button class="btn btn-outline-light active" data-days="7">7D</button>
                <button class="btn btn-outline-light" data-days="30">30D</button>
                <button class="btn btn-outline-light" data-days="90">90D</button>
            </div>
        </div>
    </div>

    {{-- ========== KPI CARDS ========== --}}
    <div class="row g-3 mb-4">
        {{-- Today's Revenue --}}
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Today's Revenue</div>
                    <div class="h4 mb-0 mt-1 fw-bold">Tk {{ number_format($kpis['today_revenue'], 0) }}</div>
                    <div class="small text-muted">{{ $kpis['today_invoices'] }} invoice{{ $kpis['today_invoices'] !== 1 ? 's' : '' }}</div>
                </div>
            </div>
        </div>
        {{-- MTD Revenue --}}
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">MTD Revenue</div>
                    <div class="h4 mb-0 mt-1 fw-bold text-success">Tk {{ number_format($kpis['mtd_revenue'], 0) }}</div>
                    <div class="small {{ $kpis['revenue_growth'] >= 0 ? 'text-success' : 'text-danger' }}">
                        <i class="fas fa-{{ $kpis['revenue_growth'] >= 0 ? 'arrow-up' : 'arrow-down' }}"></i>
                        {{ abs($kpis['revenue_growth']) }}% vs last month
                    </div>
                </div>
            </div>
        </div>
        {{-- MTD Collection --}}
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">MTD Collection</div>
                    <div class="h4 mb-0 mt-1 fw-bold text-info">Tk {{ number_format($kpis['mtd_collection'], 0) }}</div>
                    <div class="small text-muted">{{ $kpis['collection_rate'] }}% collection rate</div>
                </div>
            </div>
        </div>
        {{-- MTD Due --}}
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">MTD Outstanding</div>
                    <div class="h4 mb-0 mt-1 fw-bold text-warning">Tk {{ number_format($kpis['mtd_due'], 0) }}</div>
                    <div class="small text-muted">Due this month</div>
                </div>
            </div>
        </div>
        {{-- Total Outstanding --}}
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Total Outstanding</div>
                    <div class="h4 mb-0 mt-1 fw-bold text-danger">Tk {{ number_format($kpis['total_outstanding'], 0) }}</div>
                    <div class="small text-muted">All active invoices</div>
                </div>
            </div>
        </div>
        {{-- Collection Rate --}}
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Collection Rate</div>
                    <div class="h4 mb-0 mt-1 fw-bold {{ $kpis['collection_rate'] >= 80 ? 'text-success' : ($kpis['collection_rate'] >= 50 ? 'text-warning' : 'text-danger') }}">
                        {{ $kpis['collection_rate'] }}%
                    </div>
                    <div class="small text-muted">
                        {{ $kpis['mtd_invoices'] }} invoice{{ $kpis['mtd_invoices'] !== 1 ? 's' : '' }} MTD
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ========== CHARTS ROW 1 ========== --}}
    <div class="row g-3 mb-4">
        {{-- Sales Trend (main chart — 8 cols) --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-chart-line me-1 text-primary"></i> Sales Trend</h3>
                    <span class="badge bg-primary-subtle text-primary" id="trendLabel">Last 7 days</span>
                </div>
                <div class="card-body">
                    <canvas id="salesTrendChart" height="260"></canvas>
                </div>
            </div>
        </div>
        {{-- Receivable Aging (donut — 4 cols) --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-clock-rotate-left me-1 text-warning"></i> Receivable Aging</h3>
                </div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    <canvas id="agingChart" height="220"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- ========== CHARTS ROW 2 ========== --}}
    <div class="row g-3 mb-4">
        {{-- Branch Revenue (bar — 6 cols) --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-building me-1 text-indigo"></i> Branch Revenue (MTD)</h3>
                </div>
                <div class="card-body">
                    <canvas id="branchChart" height="200"></canvas>
                </div>
            </div>
        </div>
        {{-- Revenue vs Collection (bar — 6 cols) --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-scale-balanced me-1 text-success"></i> Revenue vs Collection (MTD)</h3>
                </div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    <canvas id="revenueVsCollectionChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- ========== MINI TABLES ROW ========== --}}
    <div class="row g-3 mb-4">
        {{-- Top Customers --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-users me-1 text-primary"></i> Top Customers (MTD)</h3>
                    <a href="{{ route('admin.reports.customerPerformance') }}" class="btn btn-sm btn-outline-secondary">View all</a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th class="text-end">Revenue</th>
                                <th class="text-end">Due</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($topCustomers as $i => $c)
                                <tr>
                                    <td>{{ $i + 1 }}</td>
                                    <td>{{ $c['name'] }}</td>
                                    <td class="text-end fw-semibold">Tk {{ number_format($c['revenue'], 0) }}</td>
                                    <td class="text-end {{ $c['due'] > 0 ? 'text-danger' : '' }}">Tk {{ number_format($c['due'], 0) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No data this month.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        {{-- Top Products --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-box me-1 text-success"></i> Top Products (MTD)</h3>
                    <a href="{{ route('admin.reports.revenueOverview') }}" class="btn btn-sm btn-outline-secondary">View all</a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th class="text-end">Qty Sold</th>
                                <th class="text-end">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($topProducts as $i => $p)
                                <tr>
                                    <td>{{ $i + 1 }}</td>
                                    <td>
                                        <code>{{ $p['code'] }}</code> {{ $p['name'] }}
                                    </td>
                                    <td class="text-end">{{ number_format($p['qty'], 0) }}</td>
                                    <td class="text-end fw-semibold">Tk {{ number_format($p['revenue'], 0) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No data this month.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ========== QUICK STATS CARDS (basic) ========== --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">Customers</div>
                            <div class="h3 mb-0 mt-1">{{ number_format($stats['customers'] ?? 0) }}</div>
                        </div>
                        <i class="fas fa-users fa-2x text-primary opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">Products</div>
                            <div class="h3 mb-0 mt-1">{{ number_format($stats['products'] ?? 0) }}</div>
                        </div>
                        <i class="fas fa-box fa-2x text-success opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">Invoices Today</div>
                            <div class="h3 mb-0 mt-1">{{ number_format($stats['invoices_today'] ?? 0) }}</div>
                        </div>
                        <i class="fas fa-file-invoice fa-2x text-warning opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">Pending Challans</div>
                            <div class="h3 mb-0 mt-1">{{ number_format($stats['pending_challans'] ?? 0) }}</div>
                        </div>
                        <i class="fas fa-truck fa-2x text-info opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ============================================================
    // Color palette
    // ============================================================
    const colors = {
        primary:   '#2563eb',
        success:   '#16a34a',
        warning:   '#f59e0b',
        danger:    '#dc2626',
        info:      '#0ea5e9',
        indigo:    '#6366f1',
        slate:     '#64748b',
    };
    const alpha = (hex, a) => {
        const r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
        return `rgba(${r},${g},${b},${a})`;
    };

    // ============================================================
    // 1. Sales Trend Line Chart
    // ============================================================
    const trendData = @json($salesTrend);
    const trendLabels = trendData.map(d => {
        const dt = new Date(d.date + 'T00:00:00');
        return dt.toLocaleDateString('en-GB', { day:'2-digit', month:'short' });
    });

    const salesTrendCtx = document.getElementById('salesTrendChart').getContext('2d');
    const salesTrendChart = new Chart(salesTrendCtx, {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [
                {
                    label: 'Revenue (Tk)',
                    data: trendData.map(d => d.total_sales),
                    borderColor: colors.primary,
                    backgroundColor: alpha(colors.primary, 0.12),
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointHoverRadius: 6,
                    borderWidth: 2.5,
                    yAxisID: 'y',
                },
                {
                    label: 'Invoices',
                    data: trendData.map(d => d.invoice_count),
                    borderColor: colors.success,
                    backgroundColor: alpha(colors.success, 0.08),
                    fill: false,
                    tension: 0.35,
                    pointRadius: 2,
                    pointHoverRadius: 5,
                    borderWidth: 2,
                    borderDash: [5,3],
                    yAxisID: 'y1',
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.dataset.label === 'Revenue (Tk)'
                            ? 'Revenue: Tk ' + ctx.parsed.y.toLocaleString()
                            : 'Invoices: ' + ctx.parsed.y
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear', display: true, position: 'left',
                    ticks: { callback: v => 'Tk ' + (v/1000).toFixed(0) + 'k' },
                    grid: { color: alpha(colors.slate, 0.08) }
                },
                y1: {
                    type: 'linear', display: true, position: 'right',
                    ticks: { stepSize: 1 },
                    grid: { drawOnChartArea: false }
                },
                x: { grid: { display: false } }
            }
        }
    });

    // Trend toggle (7D / 30D / 90D)
    const trendToggle = document.getElementById('trendToggle');
    const trendLabel = document.getElementById('trendLabel');
    trendToggle.addEventListener('click', async function(e) {
        const btn = e.target.closest('button');
        if (!btn) return;
        const days = parseInt(btn.dataset.days);
        trendToggle.querySelectorAll('button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        try {
            const resp = await fetch('{{ route("dashboard.salesTrend") }}?days=' + days, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const json = await resp.json();
            const data = json.data || [];
            const labels = data.map(d => {
                const dt = new Date(d.date + 'T00:00:00');
                return dt.toLocaleDateString('en-GB', { day:'2-digit', month:'short' });
            });

            salesTrendChart.data.labels = labels;
            salesTrendChart.data.datasets[0].data = data.map(d => d.total_sales);
            salesTrendChart.data.datasets[1].data = data.map(d => d.invoice_count);
            salesTrendChart.update('active');
            trendLabel.textContent = 'Last ' + days + ' days';
        } catch(err) {
            console.error('Failed to load trend data', err);
        }
    });

    // ============================================================
    // 2. Receivable Aging Donut Chart
    // ============================================================
    const agingData = @json($agingData);
    const agingLabels = Object.keys(agingData);
    const agingValues = Object.values(agingData);
    const agingColors = [
        colors.success,   // Current
        colors.info,      // 1-30
        colors.warning,   // 31-60
        colors.primary,   // 61-90
        colors.danger,    // 90+
    ];

    new Chart(document.getElementById('agingChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: agingLabels,
            datasets: [{
                data: agingValues,
                backgroundColor: agingColors.map(c => alpha(c, 0.75)),
                borderColor: agingColors,
                borderWidth: 2,
                hoverOffset: 8,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 12, font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.label + ': Tk ' + ctx.parsed.toLocaleString()
                    }
                }
            }
        }
    });

    // ============================================================
    // 3. Branch Revenue Bar Chart
    // ============================================================
    const branchData = @json($branchRevenue);
    const branchColors = [colors.primary, colors.success, colors.warning, colors.indigo, colors.danger, colors.info, colors.slate];

    new Chart(document.getElementById('branchChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: branchData.map(b => b.branch),
            datasets: [{
                label: 'Revenue (Tk)',
                data: branchData.map(b => b.revenue),
                backgroundColor: branchData.map((_, i) => alpha(branchColors[i % branchColors.length], 0.7)),
                borderColor: branchData.map((_, i) => branchColors[i % branchColors.length]),
                borderWidth: 1.5,
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => 'Revenue: Tk ' + ctx.parsed.y.toLocaleString()
                    }
                }
            },
            scales: {
                y: { ticks: { callback: v => 'Tk ' + (v/1000).toFixed(0) + 'k' }, grid: { color: alpha(colors.slate, 0.08) } },
                x: { grid: { display: false } }
            }
        }
    });

    // ============================================================
    // 4. Revenue vs Collection Gauge Chart
    // ============================================================
    const kpis = @json($kpis);
    new Chart(document.getElementById('revenueVsCollectionChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: ['MTD Revenue', 'MTD Collection', 'MTD Outstanding', 'Total Outstanding'],
            datasets: [{
                data: [kpis.mtd_revenue, kpis.mtd_collection, kpis.mtd_due, kpis.total_outstanding],
                backgroundColor: [
                    alpha(colors.primary, 0.75),
                    alpha(colors.success, 0.75),
                    alpha(colors.warning, 0.75),
                    alpha(colors.danger, 0.75),
                ],
                borderColor: [colors.primary, colors.success, colors.warning, colors.danger],
                borderWidth: 1.5,
                borderRadius: 6,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => 'Tk ' + ctx.parsed.x.toLocaleString()
                    }
                }
            },
            scales: {
                x: { ticks: { callback: v => 'Tk ' + (v/1000).toFixed(0) + 'k' }, grid: { color: alpha(colors.slate, 0.08) } },
                y: { grid: { display: false } }
            }
        }
    });
});
</script>
@endpush
