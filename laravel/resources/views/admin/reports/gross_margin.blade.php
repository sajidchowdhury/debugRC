@extends('layouts.admin')

@php
    $avgMargin = $totals['revenue'] > 0 ? ($totals['gross_profit'] / $totals['revenue'] * 100) : 0;
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-percent me-2 text-primary"></i> Gross Margin (Invoice vs COGS)</h2>
            <p class="text-muted mb-0 small">True margin on delivery basis — {{ \Carbon\Carbon::parse($meta['from_date'])->format('d M Y') }} → {{ \Carbon\Carbon::parse($meta['to_date'])->format('d M Y') }}.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.grossMargin') }}" class="row g-2 align-items-end">
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
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Summary cards --}}
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:38px;height:38px;background:#2563eb;"><i class="fas fa-money-bill-trend-up"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">Tk {{ number_format($totals['revenue'], 2) }}</div>
                        <small class="text-muted">Total revenue</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:38px;height:38px;background:#dc2626;"><i class="fas fa-truck"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1 text-danger">Tk {{ number_format($totals['cogs'], 2) }}</div>
                        <small class="text-muted">Total COGS</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:38px;height:38px;background:#16a34a;"><i class="fas fa-coins"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1 {{ $totals['gross_profit'] >= 0 ? 'text-success' : 'text-danger' }}">Tk {{ number_format($totals['gross_profit'], 2) }}</div>
                        <small class="text-muted">Gross profit</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:38px;height:38px;background:#6366f1;"><i class="fas fa-percent"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1 {{ $avgMargin >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($avgMargin, 2) }}%</div>
                        <small class="text-muted">Avg margin</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="gmTable" class="table table-sm table-striped table-hover mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">COGS</th>
                            <th class="text-end">Gross Profit</th>
                            <th class="text-end">Margin %</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data as $r)
                            <tr>
                                <td><code>{{ $r->invoice_code }}</code></td>
                                <td>{{ \Carbon\Carbon::parse($r->invoice_date)->format('d M Y') }}</td>
                                <td>{{ $r->customer_name ?? '—' }}</td>
                                <td class="text-end">{{ number_format($r->revenue, 2) }}</td>
                                <td class="text-end text-danger">{{ number_format($r->cogs, 2) }}</td>
                                <td class="text-end fw-semibold {{ $r->gross_profit >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($r->gross_profit, 2) }}</td>
                                <td class="text-end">
                                    <span class="badge bg-{{ $r->margin_pct >= 20 ? 'success' : ($r->margin_pct >= 0 ? 'warning' : 'danger') }}-subtle text-{{ $r->margin_pct >= 20 ? 'success' : ($r->margin_pct >= 0 ? 'warning text-dark' : 'danger') }}">
                                        {{ number_format($r->margin_pct, 2) }}%
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-muted text-center py-3">No invoices in the selected period.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="3" class="text-end">TOTAL</td>
                            <td class="text-end">{{ number_format($totals['revenue'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['cogs'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['gross_profit'], 2) }}</td>
                            <td class="text-end">{{ number_format($avgMargin, 2) }}%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function() {
    $('#gmTable').DataTable({
        paging: false,
        searching: true,
        ordering: true,
        info: false,
        order: [[5, 'desc']]
    });
});
</script>
@endpush
