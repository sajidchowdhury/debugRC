@extends('layouts.admin')

@section('content')
@php
    $computedAt = $summary['computed_at'] ?? null;
    $totalProducts = $summary['total_products'] ?? 0;
    $totalValue = $summary['total_usage_value'] ?? 0;
    $classes = $summary['classes'] ?? [];

    // Group the per-warehouse breakdown by warehouse for the table.
    $byWh = $perWarehouse->groupBy('warehouse_id');
@endphp

<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#059669,#0891b2);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-chart-line me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                ABC classification ranks products by annual usage value so high-value movers (A) can be cycle-counted
                more often than dead stock (C). Computed nightly from outbound stock transactions.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.stock-take.create') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-plus me-1"></i> New cycle count
            </a>
            <form method="POST" action="{{ route('admin.stock-take.abc.refresh') }}">
                @csrf
                <button type="submit" class="btn btn-light btn-sm"
                        onclick="this.disabled=true;this.innerHTML='<i class=\'fas fa-spinner fa-spin me-1\'></i> Refreshing…';this.form.submit();">
                    <i class="fas fa-rotate me-1"></i> Refresh now
                </button>
            </form>
        </div>
    </header>

    {{-- Policy + freshness summary --}}
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Class A threshold</div>
                    <div class="fw-bold fs-5">{{ number_format($thresholdA * 100, 0) }}%</div>
                    <div class="text-muted" style="font-size:.75rem">Top {{ number_format($thresholdA * 100, 0) }}% of usage value</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">A + B threshold</div>
                    <div class="fw-bold fs-5">{{ number_format($thresholdB * 100, 0) }}%</div>
                    <div class="text-muted" style="font-size:.75rem">B spans {{ number_format($thresholdA*100,0) }}–{{ number_format($thresholdB*100,0) }}%; C is the rest</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Lookback window</div>
                    <div class="fw-bold fs-5">{{ $lookbackDays }} days</div>
                    <div class="text-muted" style="font-size:.75rem">Outbound consumption window</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Last computed</div>
                    <div class="fw-bold fs-6">
                        @if ($computedAt)
                            {{ \Carbon\Carbon::parse($computedAt)->format('d M Y H:i') }}
                        @else
                            <span class="text-warning">Never</span>
                        @endif
                    </div>
                    <div class="text-muted" style="font-size:.75rem">{{ number_format($totalProducts) }} products classified</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter --}}
    <form method="GET" class="mb-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex flex-wrap align-items-end gap-2">
                <div class="flex-grow-1" style="min-width:220px">
                    <label class="form-label small mb-1">Warehouse</label>
                    <select name="warehouse_id" class="form-select form-select-sm">
                        <option value="">All warehouses</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}" {{ (string)$selectedWarehouseId === (string)$w->id ? 'selected' : '' }}>
                                {{ $w->warehouse_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i> Filter</button>
                <a href="{{ route('admin.stock-take.abc-report') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
            </div>
        </div>
    </form>

    {{-- Aggregate distribution --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0"><i class="fas fa-chart-pie me-1 text-primary"></i> Distribution {{ $selectedWarehouseId ? '(filtered warehouse)' : '(all warehouses)' }}</h2>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @foreach (['A','B','C'] as $cls)
                    @php $row = $classes[$cls] ?? ['count'=>0,'total_usage_value'=>0,'share'=>0]; @endphp
                    <div class="col-md-4">
                        <div class="border rounded-3 p-3 h-100 text-center">
                            <div class="badge bg-{{ $cls === 'A' ? 'success' : ($cls === 'B' ? 'warning' : 'secondary') }} mb-2" style="font-size:.9rem">Class {{ $cls }}</div>
                            <div class="fw-bold fs-3">{{ number_format($row['count']) }}</div>
                            <div class="text-muted small">products</div>
                            <hr class="my-2">
                            <div class="fw-semibold">{{ number_format($row['total_usage_value'], 2) }}</div>
                            <div class="text-muted small">annual usage value</div>
                            <div class="progress mt-2" style="height:6px">
                                <div class="progress-bar bg-{{ $cls === 'A' ? 'success' : ($cls === 'B' ? 'warning' : 'secondary') }}"
                                     style="width: {{ number_format($row['share'] * 100, 1) }}%"></div>
                            </div>
                            <div class="text-muted mt-1" style="font-size:.7rem">{{ number_format($row['share'] * 100, 1) }}% of total value</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Per-warehouse breakdown --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0"><i class="fas fa-table me-1 text-primary"></i> Per-warehouse breakdown</h2>
        </div>
        <div class="card-body p-0">
            @if ($byWh->isEmpty())
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                    No ABC data yet. Click <strong>Refresh now</strong> to compute.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Warehouse</th>
                                <th class="text-center">A (count)</th>
                                <th class="text-center">A (value)</th>
                                <th class="text-center">B (count)</th>
                                <th class="text-center">B (value)</th>
                                <th class="text-center">C (count)</th>
                                <th class="text-center">C (value)</th>
                                <th class="text-center">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $grandCounts = ['A'=>0,'B'=>0,'C'=>0]; $grandValues = ['A'=>0.0,'B'=>0.0,'C'=>0.0]; @endphp
                            @foreach ($byWh as $wid => $rows)
                                @php
                                    $byClass = $rows->keyBy('abc_class');
                                    $whName = $rows->first()->warehouse_name;
                                    $cnt = fn($c) => $byClass->has($c) ? (int)$byClass[$c]->product_count : 0;
                                    $val = fn($c) => $byClass->has($c) ? (float)$byClass[$c]->total_usage_value : 0.0;
                                    foreach (['A','B','C'] as $c) { $grandCounts[$c] += $cnt($c); $grandValues[$c] += $val($c); }
                                    $whTotal = $cnt('A') + $cnt('B') + $cnt('C');
                                @endphp
                                <tr>
                                    <td>
                                        @if ($selectedWarehouseId)
                                            <strong>{{ $whName }}</strong>
                                        @else
                                            <a href="?warehouse_id={{ $wid }}" class="text-decoration-none">{{ $whName }} <i class="fas fa-filter small ms-1"></i></a>
                                        @endif
                                    </td>
                                    <td class="text-center"><span class="badge bg-success-subtle text-success">{{ $cnt('A') }}</span></td>
                                    <td class="text-end small">{{ number_format($val('A'), 2) }}</td>
                                    <td class="text-center"><span class="badge bg-warning-subtle text-warning">{{ $cnt('B') }}</span></td>
                                    <td class="text-end small">{{ number_format($val('B'), 2) }}</td>
                                    <td class="text-center"><span class="badge bg-secondary-subtle text-secondary">{{ $cnt('C') }}</span></td>
                                    <td class="text-end small">{{ number_format($val('C'), 2) }}</td>
                                    <td class="text-center fw-semibold">{{ $whTotal }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light fw-semibold">
                            <tr>
                                <td>Total</td>
                                <td class="text-center">{{ $grandCounts['A'] }}</td>
                                <td class="text-end small">{{ number_format($grandValues['A'], 2) }}</td>
                                <td class="text-center">{{ $grandCounts['B'] }}</td>
                                <td class="text-end small">{{ number_format($grandValues['B'], 2) }}</td>
                                <td class="text-center">{{ $grandCounts['C'] }}</td>
                                <td class="text-end small">{{ number_format($grandValues['C'], 2) }}</td>
                                <td class="text-center">{{ array_sum($grandCounts) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- Top A-class products --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0"><i class="fas fa-trophy me-1 text-success"></i> Top 50 A-class products (highest annual usage value)</h2>
        </div>
        <div class="card-body p-0">
            @if ($topProducts->isEmpty())
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                    No A-class products. Refresh the classification or broaden the warehouse filter.
                </div>
            @else
                <div class="table-responsive" style="max-height:480px;overflow:auto">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light" style="position:sticky;top:0">
                            <tr>
                                <th>Code</th>
                                <th>Product</th>
                                <th>Warehouse</th>
                                <th class="text-end">Annual usage value</th>
                                <th class="text-center">Class</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($topProducts as $p)
                                <tr>
                                    <td><code>{{ $p->product_code }}</code></td>
                                    <td>{{ $p->product_name }}</td>
                                    <td><span class="small text-muted">{{ $p->warehouse_name }}</span></td>
                                    <td class="text-end fw-semibold">{{ number_format($p->annual_usage_value, 2) }}</td>
                                    <td class="text-center"><span class="badge bg-success-subtle text-success">{{ $p->abc_class }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="text-muted small">
        <i class="fas fa-circle-info me-1"></i>
        Annual usage value = SUM(ABS(qty) × rate) for outbound (qty &lt; 0) non-reversed stock transactions within the {{ $lookbackDays }}-day lookback.
        Classification is per-warehouse (each warehouse has its own A/B/C distribution).
        The materialized view refreshes nightly at 01:30 via pg_cron; use <strong>Refresh now</strong> for an on-demand update (CONCURRENTLY — readers are never blocked).
    </div>
</div>
@endsection
