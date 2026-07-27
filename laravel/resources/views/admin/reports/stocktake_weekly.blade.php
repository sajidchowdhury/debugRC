@extends('layouts.admin')

@php
    // Phase 6 (Stock Take plan): weekly control report.
    $statusBadge = [
        'draft'      => 'secondary',
        'counting'   => 'primary',
        'submitted'  => 'warning',
        'approved'   => 'info',
        'posted'     => 'success',
        'cancelled'  => 'danger',
        'reversed'   => 'dark',
    ];
    $fmt = fn($v) => number_format((float) $v, 2);
    $totals = $totals ?? [];
    $sessions = $sessions ?? [];
    $topProducts = $top_products ?? [];
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-chart-line me-2 text-primary"></i> Stock Take — Weekly Control</h2>
            <p class="text-muted mb-0 small">Posted &amp; in-flight sessions, gain/loss totals, and top SKU variances — {{ \Carbon\Carbon::parse($meta['from_date'])->format('d M Y') }} → {{ \Carbon\Carbon::parse($meta['to_date'])->format('d M Y') }}.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.stocktakeVariance') }}" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-table me-1"></i> Variance detail
            </a>
            <a href="{{ route('admin.reports.stocktakeWeeklyExport', request()->query()) }}" class="btn btn-outline-success btn-sm">
                <i class="fas fa-file-csv me-1"></i> CSV
            </a>
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
        </div>
    </div>

    {{-- Period + branch filter --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.stocktakeWeekly') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="{{ old('from_date', $meta['from_date'] ?? '') }}">
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">To</label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="{{ old('to_date', $meta['to_date'] ?? '') }}">
                </div>
                @if (!empty($is_admin))
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">— All branches —</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected((int)($branch_id ?? 0) === (int)$b->id)>{{ $b->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="col-md-3 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                    <a href="{{ route('admin.reports.stocktakeWeekly') }}" class="btn btn-outline-secondary btn-sm" title="Reset">
                        <i class="fas fa-rotate-left"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Summary bar --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap gap-3 align-items-center small">
                <span>Sessions: <strong>{{ number_format($totals['sessions'] ?? 0) }}</strong></span>
                <span class="text-success">Posted: <strong>{{ number_format($totals['posted'] ?? 0) }}</strong></span>
                <span class="text-dark">Reversed: <strong>{{ number_format($totals['reversed'] ?? 0) }}</strong></span>
                <span class="text-warning">Open: <strong>{{ number_format($totals['open'] ?? 0) }}</strong></span>
                <span>Variance lines: <strong>{{ number_format($totals['variance_lines'] ?? 0) }}</strong></span>
                <span class="text-success">Gain: <strong>{{ $fmt($totals['gain_value'] ?? 0) }}</strong></span>
                <span class="text-danger">Loss: <strong>{{ $fmt($totals['loss_value'] ?? 0) }}</strong></span>
                <span>Net: <strong class="{{ ($totals['net_value'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">{{ ($totals['net_value'] ?? 0) >= 0 ? '+' : '' }}{{ $fmt($totals['net_value'] ?? 0) }}</strong></span>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- Sessions in period --}}
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 fw-semibold"><i class="fas fa-list me-1 text-primary"></i> Sessions in period</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Session</th>
                                    <th>Date</th>
                                    <th>Branch</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-end">WH done</th>
                                    <th class="text-end">Lines</th>
                                    <th class="text-end">Net value</th>
                                    <th class="text-center">GL</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($sessions as $s)
                                    @php
                                        $effectiveStatus = !empty($s->is_reversed) ? 'reversed' : ($s->status ?? '');
                                        $net = (float) ($s->net_value ?? 0);
                                    @endphp
                                    <tr>
                                        <td>
                                            <a href="{{ route('admin.stock-take.show', $s->id) }}" class="text-decoration-none">
                                                <code>{{ $s->session_code }}</code>
                                            </a>
                                        </td>
                                        <td class="text-nowrap small">{{ \Carbon\Carbon::parse($s->session_date)->format('d M Y') }}</td>
                                        <td class="small">{{ $s->branch_name ?? '—' }}</td>
                                        <td class="text-center">
                                            <span class="badge bg-{{ $statusBadge[$effectiveStatus] ?? 'secondary' }}-subtle text-{{ $statusBadge[$effectiveStatus] ?? 'secondary' }}">
                                                {{ ucfirst(str_replace('_', ' ', $effectiveStatus)) }}
                                            </span>
                                        </td>
                                        <td class="text-end font-monospace small">{{ $s->warehouses_done ?? 0 }}/{{ $s->warehouse_count ?? 0 }}</td>
                                        <td class="text-end font-monospace small">{{ number_format($s->variance_lines ?? 0) }}</td>
                                        <td class="text-end font-monospace small fw-semibold {{ $net >= 0 ? 'text-success' : 'text-danger' }}">
                                            {{ $net >= 0 ? '+' : '' }}{{ $fmt($net) }}
                                        </td>
                                        <td class="text-center small">
                                            @if (!empty($s->journal_entry_id))
                                                <a href="{{ route('admin.reports.journalEntries', ['reference_type' => 'stock_take', 'from_date' => $meta['from_date'], 'to_date' => $meta['to_date']]) }}" class="text-decoration-none" title="View in journal entries report">
                                                    <i class="fas fa-book-open text-primary"></i>
                                                </a>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted py-4">No stock-take sessions in this period.</td></tr>
                                @endforelse
                            </tbody>
                            @if (!empty($sessions))
                            <tfoot class="table-light fw-semibold">
                                <tr>
                                    <td colspan="5" class="text-end">Totals</td>
                                    <td class="text-end font-monospace">{{ number_format($totals['variance_lines'] ?? 0) }}</td>
                                    <td class="text-end font-monospace {{ ($totals['net_value'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">{{ ($totals['net_value'] ?? 0) >= 0 ? '+' : '' }}{{ $fmt($totals['net_value'] ?? 0) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Top variance products --}}
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 fw-semibold"><i class="fas fa-trophy me-1 text-warning"></i> Top variances by value</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Abs value</th>
                                    <th class="text-end">+ / − lines</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($topProducts as $p)
                                    <tr>
                                        <td class="small">
                                            <div class="fw-semibold font-monospace">{{ $p->product_code }}</div>
                                            <div class="text-muted" style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $p->product_name }}</div>
                                        </td>
                                        <td class="text-end font-monospace small">{{ $fmt($p->abs_value_variance) }}</td>
                                        <td class="text-end font-monospace small">
                                            <span class="text-success">+{{ $p->surplus_lines }}</span>
                                            <span class="text-muted">/</span>
                                            <span class="text-danger">−{{ $p->shortage_lines }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted text-center py-4">No variances recorded in this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
