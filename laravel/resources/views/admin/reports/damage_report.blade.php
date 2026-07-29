@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-triangle-exclamation me-2 text-danger"></i> Damage Report</h2>
            <p class="text-muted mb-0 small">
                Damage &amp; loss cost analysis for the period
                <strong>{{ \Carbon\Carbon::parse($meta['from_date'])->format('d M Y') }}</strong>
                &rarr; <strong>{{ \Carbon\Carbon::parse($meta['to_date'])->format('d M Y') }}</strong>.
                Only confirmed (posted) damages contribute to cost totals.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
            <a href="{{ route('admin.reports.damageReportExport', request()->query()) }}" class="btn btn-outline-success btn-sm">
                <i class="fas fa-file-csv me-1"></i> CSV
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.damageReport') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">From date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="{{ old('from_date', request('from_date', $meta['from_date'])) }}">
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">To date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="{{ old('to_date', request('to_date', $meta['to_date'])) }}">
                </div>
                @if ($is_admin)
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) old('branch_id', request('branch_id', $meta['branch_id'] ?? '')) === $b->id)>{{ $b->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">Warehouse</label>
                    <select name="warehouse_id" class="form-select form-select-sm">
                        <option value="">All warehouses</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}" @selected((int) old('warehouse_id', request('warehouse_id')) === $w->id)>{{ $w->warehouse_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">Damage type</label>
                    <select name="damage_type" class="form-select form-select-sm">
                        <option value="">All types</option>
                        @foreach ($damageTypes as $value => $label)
                            <option value="{{ $value }}" @selected(old('damage_type', request('damage_type')) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All statuses</option>
                        @foreach (['draft'=>'Draft','submitted'=>'Submitted','approved'=>'Approved','confirmed'=>'Confirmed','cancelled'=>'Cancelled','rejected'=>'Rejected'] as $val => $lbl)
                            <option value="{{ $val }}" @selected(old('status', request('status')) === $val)>{{ $lbl }}</option>
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

    {{-- KPI summary cards --}}
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card border-0 shadow-sm h-100 border-start border-danger border-3">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Damage Cost (Period)</small>
                    <div class="h5 mb-0 mt-1 fw-bold text-danger">Tk {{ number_format($kpi['mtd_value'], 2) }}</div>
                    <small class="text-muted">{{ $kpi['mtd_count'] }} confirmed damage{{ $kpi['mtd_count'] !== 1 ? 's' : '' }}</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card border-0 shadow-sm h-100 border-start border-warning border-3">
                <div class="card-body py-2">
                    <small class="text-muted d-block">vs Previous Period</small>
                    <div class="h5 mb-0 mt-1 fw-bold {{ $kpi['growth_pct'] >= 0 ? 'text-danger' : 'text-success' }}">
                        <i class="fas fa-{{ $kpi['growth_pct'] >= 0 ? 'arrow-up' : 'arrow-down' }}"></i>
                        {{ abs($kpi['growth_pct']) }}%
                    </div>
                    <small class="text-muted">Prev: Tk {{ number_format($kpi['prev_value'], 0) }}</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card border-0 shadow-sm h-100 border-start border-info border-3">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Recovered</small>
                    <div class="h5 mb-0 mt-1 fw-bold text-info">Tk {{ number_format($kpi['recovered'], 2) }}</div>
                    <small class="text-muted">from employees</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card border-0 shadow-sm h-100 border-start border-dark border-3">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Net Loss</small>
                    <div class="h5 mb-0 mt-1 fw-bold text-dark">Tk {{ number_format($kpi['net_loss'], 2) }}</div>
                    <small class="text-muted">after recovery</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card border-0 shadow-sm h-100 border-start border-primary border-3">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Detail Rows</small>
                    <div class="h5 mb-0 mt-1 fw-bold">{{ $summary['total_count'] }}</div>
                    <small class="text-muted">{{ $summary['confirmed_count'] }} confirmed</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card border-0 shadow-sm h-100 border-start border-secondary border-3">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Awaiting Approval</small>
                    <div class="h5 mb-0 mt-1 fw-bold text-secondary">{{ $summary['awaiting_count'] }}</div>
                    <small class="text-muted">Tk {{ number_format($summary['awaiting_value'], 0) }} pending</small>
                </div>
            </div>
        </div>
    </div>

    {{-- Charts row --}}
    <div class="row g-3 mb-3">
        {{-- Monthly trend line chart --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-chart-line me-1 text-primary"></i> Monthly Trend</h3>
                </div>
                <div class="card-body">
                    <canvas id="monthlyTrendChart" height="120"></canvas>
                </div>
            </div>
        </div>
        {{-- Category donut --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-chart-pie me-1 text-danger"></i> By Category</h3>
                </div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    <canvas id="categoryChart" height="160"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        {{-- Warehouse bar chart --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-warehouse me-1 text-info"></i> By Warehouse</h3>
                </div>
                <div class="card-body">
                    <canvas id="warehouseChart" height="160"></canvas>
                </div>
            </div>
        </div>
        {{-- Status distribution --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h3 class="h6 mb-0"><i class="fas fa-list-check me-1 text-secondary"></i> Status Distribution</h3>
                </div>
                <div class="card-body">
                    <canvas id="statusChart" height="160"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Breakdown tables --}}
    <div class="row g-3 mb-3">
        {{-- By employee --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <h3 class="h6 mb-0"><i class="fas fa-user-shield me-1 text-warning"></i> By Accountable Employee</h3>
                    <small class="text-muted">{{ count($byEmployee) }} employees</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 360px; overflow-y: auto;">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Employee</th>
                                    <th class="text-end">Count</th>
                                    <th class="text-end">Liable</th>
                                    <th class="text-end">Recovered</th>
                                    <th class="text-end">Outstanding</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($byEmployee as $r)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $r->employee_name }}</div>
                                            <small class="text-muted">{{ $r->employee_code }} @if($r->role) &middot; {{ $r->role }}@endif</small>
                                        </td>
                                        <td class="text-end">{{ $r->damage_count }}</td>
                                        <td class="text-end text-danger">Tk {{ number_format($r->total_liable, 2) }}</td>
                                        <td class="text-end text-info">Tk {{ number_format($r->total_recovered, 2) }}</td>
                                        <td class="text-end fw-bold {{ $r->outstanding > 0 ? 'text-warning' : 'text-success' }}">Tk {{ number_format($r->outstanding, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-muted text-center py-3">No accountable employees in this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        {{-- Top products --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <h3 class="h6 mb-0"><i class="fas fa-box-open me-1 text-primary"></i> Top 20 Damaged Products</h3>
                    <small class="text-muted">by cost</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 360px; overflow-y: auto;">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th width="40">#</th>
                                    <th>Product</th>
                                    <th class="text-end">Count</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($topProducts as $i => $r)
                                    <tr>
                                        <td class="text-muted">{{ $i + 1 }}</td>
                                        <td>
                                            <div class="fw-semibold">{{ $r->product_name }}</div>
                                            <small class="text-muted">{{ $r->product_code }}</small>
                                        </td>
                                        <td class="text-end">{{ $r->damage_count }}</td>
                                        <td class="text-end">{{ number_format($r->total_qty, 2) }}</td>
                                        <td class="text-end fw-semibold text-danger">Tk {{ number_format($r->total_cost, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-muted text-center py-3">No damaged products in this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Category & warehouse tables (for print / detail) --}}
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-2"><h3 class="h6 mb-0"><i class="fas fa-tags me-1 text-danger"></i> Category Breakdown</h3></div>
                <div class="card-body p-0">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light"><tr><th>Category</th><th class="text-end">Count</th><th class="text-end">Cost</th><th class="text-end">Recovered</th><th class="text-end">Net Loss</th></tr></thead>
                        <tbody>
                            @forelse ($byCategory as $r)
                                <tr>
                                    <td><span class="badge bg-danger-subtle text-danger">{{ $r->label }}</span></td>
                                    <td class="text-end">{{ $r->damage_count }}</td>
                                    <td class="text-end">Tk {{ number_format($r->total_cost, 2) }}</td>
                                    <td class="text-end">Tk {{ number_format($r->recovered, 2) }}</td>
                                    <td class="text-end fw-semibold">Tk {{ number_format($r->net_loss, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-muted text-center py-2">No data.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-2"><h3 class="h6 mb-0"><i class="fas fa-warehouse me-1 text-info"></i> Warehouse Breakdown</h3></div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="table-light sticky-top"><tr><th>Warehouse</th><th>Branch</th><th class="text-end">Count</th><th class="text-end">Cost</th><th class="text-end">Recovered</th></tr></thead>
                            <tbody>
                                @forelse ($byWarehouse as $r)
                                    <tr>
                                        <td><div class="fw-semibold">{{ $r->warehouse_name }}</div><small class="text-muted">{{ $r->warehouse_code }}</small></td>
                                        <td><small>{{ $r->branch_name }}</small></td>
                                        <td class="text-end">{{ $r->damage_count }}</td>
                                        <td class="text-end text-danger">Tk {{ number_format($r->total_cost, 2) }}</td>
                                        <td class="text-end text-info">Tk {{ number_format($r->recovered, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-muted text-center py-2">No data.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Detail table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
            <h3 class="h6 mb-0"><i class="fas fa-list me-1 text-primary"></i> Damage Detail</h3>
            <small class="text-muted">{{ count($detail) }} rows (max 500)</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-sm table-striped table-hover mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>Code</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Warehouse</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Reason</th>
                            <th class="text-end">Value</th>
                            <th class="text-end">Recovered</th>
                            <th>Accountable</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($detail as $r)
                            @php
                                $typeBadge = \App\Models\DamageInvoice::DAMAGE_TYPE_BADGE_CLASSES[$r->damage_type] ?? 'bg-light text-muted';
                                $typeLabel = \App\Models\DamageInvoice::DAMAGE_TYPES[$r->damage_type] ?? ucfirst(str_replace('_', ' ', $r->damage_type));
                                $statusBadge = match($r->status) {
                                    'draft' => 'bg-secondary-subtle text-secondary',
                                    'submitted' => 'bg-info-subtle text-info',
                                    'approved' => 'bg-primary-subtle text-primary',
                                    'confirmed' => 'bg-success-subtle text-success',
                                    'cancelled' => 'bg-light text-muted',
                                    'rejected' => 'bg-danger-subtle text-danger',
                                    default => 'bg-light text-muted',
                                };
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('admin.damages.show', $r->id) }}" class="text-decoration-none fw-semibold">{{ $r->damage_code }}</a>
                                </td>
                                <td><small>{{ \Carbon\Carbon::parse($r->damage_date)->format('d M Y') }}</small></td>
                                <td><small>{{ $r->branch_name }}</small></td>
                                <td><small>{{ $r->warehouse_name }}</small></td>
                                <td><span class="badge {{ $typeBadge }}">{{ $typeLabel }}</span></td>
                                <td><span class="badge {{ $statusBadge }}">{{ ucfirst($r->status) }}</span></td>
                                <td><small class="text-muted">{{ \Illuminate\Support\Str::limit($r->reason ?? $r->reason_code ?? '', 40) }}</small></td>
                                <td class="text-end fw-semibold {{ $r->status === 'confirmed' ? 'text-danger' : '' }}">Tk {{ number_format($r->total_value, 2) }}</td>
                                <td class="text-end">{{ $r->recovery_amount > 0 ? 'Tk ' . number_format($r->recovery_amount, 2) : '&mdash;' }}</td>
                                <td><small>{{ $r->accountable_name ?? '&mdash;' }}</small></td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-muted text-center py-4">No damage records in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script src="/assets/js/bootstrep/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {

    const colors = {
        primary: '#2563eb', success: '#16a34a', warning: '#f59e0b',
        danger:  '#dc2626', info:     '#0ea5e9', dark:   '#1f2937',
        slate:   '#64748b', purple: '#7c3aed',
    };
    const palette = ['#dc2626', '#f59e0b', '#0ea5e9', '#6366f1', '#10b981', '#64748b', '#7c3aed', '#ec4899'];
    const alpha = (hex, a) => {
        const r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
        return `rgba(${r},${g},${b},${a})`;
    };

    // ---- Monthly trend (stacked bar by type) ----
    const monthlyRaw = @json($monthly);
    const months = [...new Set(monthlyRaw.map(r => r.month))].sort();
    const types  = [...new Set(monthlyRaw.map(r => r.damage_type))];
    const typeLabels = @json($damageTypes);
    const monthlyDatasets = types.map((t, i) => ({
        label: typeLabels[t] || t,
        data: months.map(m => {
            const row = monthlyRaw.find(r => r.month === m && r.damage_type === t);
            return row ? parseFloat(row.total_cost) : 0;
        }),
        backgroundColor: alpha(palette[i % palette.length], 0.75),
        borderColor: palette[i % palette.length],
        borderWidth: 1,
    }));
    new Chart(document.getElementById('monthlyTrendChart'), {
        type: 'bar',
        data: { labels: months.map(m => { const [y,mo] = m.split('-'); return new Date(y, mo-1, 1).toLocaleDateString('en', {month:'short', year:'numeric'}); }), datasets: monthlyDatasets },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, ticks: { callback: v => 'Tk ' + v.toLocaleString() } } },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 8, font: { size: 11 } } }, tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': Tk ' + ctx.parsed.y.toLocaleString() } } }
        }
    });

    // ---- Category donut ----
    const catRaw = @json($byCategory);
    new Chart(document.getElementById('categoryChart'), {
        type: 'doughnut',
        data: {
            labels: catRaw.map(r => r.label),
            datasets: [{
                data: catRaw.map(r => parseFloat(r.total_cost)),
                backgroundColor: catRaw.map((_, i) => alpha(palette[i % palette.length], 0.75)),
                borderColor: '#fff', borderWidth: 2, hoverOffset: 8,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '55%',
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 8, font: { size: 11 } } }, tooltip: { callbacks: { label: ctx => ctx.label + ': Tk ' + ctx.parsed.toLocaleString() } } }
        }
    });

    // ---- Warehouse horizontal bar ----
    const whRaw = @json($byWarehouse);
    new Chart(document.getElementById('warehouseChart'), {
        type: 'bar',
        data: {
            labels: whRaw.map(r => r.warehouse_name),
            datasets: [{
                label: 'Cost',
                data: whRaw.map(r => parseFloat(r.total_cost)),
                backgroundColor: alpha(colors.info, 0.75),
                borderColor: colors.info, borderWidth: 1,
            }]
        },
        options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            scales: { x: { ticks: { callback: v => 'Tk ' + v.toLocaleString() } } },
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => 'Tk ' + ctx.parsed.x.toLocaleString() } } }
        }
    });

    // ---- Status distribution bar ----
    const stRaw = @json($byStatus);
    const statusColors = { draft: colors.slate, submitted: colors.info, approved: colors.primary, confirmed: colors.success, cancelled: '#9ca3af', rejected: colors.danger };
    new Chart(document.getElementById('statusChart'), {
        type: 'bar',
        data: {
            labels: stRaw.map(r => r.status.charAt(0).toUpperCase() + r.status.slice(1)),
            datasets: [{
                label: 'Count',
                data: stRaw.map(r => parseInt(r.damage_count)),
                backgroundColor: stRaw.map(r => alpha(statusColors[r.status] || colors.slate, 0.75)),
                borderColor: stRaw.map(r => statusColors[r.status] || colors.slate),
                borderWidth: 1,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
            plugins: { legend: { display: false }, tooltip: { callbacks: { afterLabel: ctx => 'Value: Tk ' + parseFloat(stRaw[ctx.dataIndex].total_value).toLocaleString() } } }
        }
    });

});
</script>
@endpush
