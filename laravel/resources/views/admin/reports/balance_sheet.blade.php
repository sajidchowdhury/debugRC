@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-building-columns me-2 text-primary"></i> Balance Sheet</h2>
            <p class="text-muted mb-0 small">
                As of {{ \Carbon\Carbon::parse($meta['as_of_date'])->format('d-m-Y') }}
                @if($meta['branch_id']) &middot; Branch #{{ $meta['branch_id'] }} @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.balanceSheet', array_merge(request()->query(), ['export' => 'csv'])) }}"
               class="btn btn-outline-success btn-sm">
                <i class="fas fa-file-csv me-1"></i> CSV
            </a>
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-print me-1"></i> Print
            </button>
        </div>
    </div>

    {{-- Filter --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.balanceSheet') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">As of date</label>
                    <input type="date" name="as_of_date" class="form-control form-control-sm"
                           value="{{ old('as_of_date', request('as_of_date', $meta['as_of_date'] ?? '')) }}">
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach (\App\Models\Branch::active()->orderBy('branch_name')->get() as $b)
                            <option value="{{ $b->id }}" @selected((int) old('branch_id', request('branch_id', $meta['branch_id'] ?? '')) === $b->id)>{{ $b->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-3 d-flex align-items-center pb-1">
                    <div class="form-check">
                        <input type="checkbox" name="include_zero" value="1" class="form-check-input" id="incZero"
                               @checked(old('include_zero', request('include_zero', false)))>
                        <label class="form-check-label small" for="incZero">Include zero-balance</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Balance check --}}
    <div class="d-flex gap-2 mb-3">
        <span class="badge {{ $checks['balanced'] ? 'bg-success' : 'bg-danger' }} py-2 px-3">
            <i class="fas fa-{{ $checks['balanced'] ? 'check-circle' : 'exclamation-triangle' }} me-1"></i>
            {{ $checks['balanced'] ? 'Balanced: Assets = Liabilities + Equity' : 'OUT OF BALANCE — Variance: ' . number_format(abs($totals['total_assets'] - $totals['total_liabilities_equity']), 2) }}
        </span>
    </div>

    {{-- Summary cards --}}
    <div class="row g-2 mb-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Total Assets</small>
                    <div class="fs-5 fw-bold text-primary">{{ number_format($totals['total_assets'], 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Total Liabilities</small>
                    <div class="fs-5 fw-bold text-warning">{{ number_format($totals['total_liabilities'], 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Total Equity</small>
                    <div class="fs-5 fw-bold text-info">{{ number_format($totals['total_equity'], 2) }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Two-column layout --}}
    <div class="row g-3">
        {{-- Assets --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-primary text-white py-2">
                    <i class="fas fa-coins me-1"></i> Assets
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" style="font-size: 0.85rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Account</th>
                                    <th>Parent Group</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $assetGroups = $assets->groupBy('parent_group');
                                @endphp
                                @foreach ($assetGroups as $group => $rows)
                                    <tr class="table-active">
                                        <td colspan="3" class="fw-bold py-1"><i class="fas fa-folder me-1 text-muted"></i> {{ $group ?? 'Ungrouped' }}</td>
                                    </tr>
                                    @foreach ($rows as $a)
                                    <tr>
                                        <td class="ps-3">{{ $a->ledger_name }} <small class="text-muted">[{{ $a->ledger_code }}]</small></td>
                                        <td class="text-muted">{{ $a->parent_group ?? '—' }}</td>
                                        <td class="text-end fw-semibold">{{ number_format($a->net_debit, 2) }}</td>
                                    </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                            <tfoot class="table-primary fw-bold">
                                <tr>
                                    <td colspan="2" class="text-end">Total Assets</td>
                                    <td class="text-end">{{ number_format($totals['total_assets'], 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Liabilities + Equity --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-warning text-dark py-2">
                    <i class="fas fa-money-bill-wave me-1"></i> Liabilities
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" style="font-size: 0.85rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Account</th>
                                    <th>Parent Group</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $liabGroups = $liabilities->groupBy('parent_group');
                                @endphp
                                @foreach ($liabGroups as $group => $rows)
                                    <tr class="table-active">
                                        <td colspan="3" class="fw-bold py-1"><i class="fas fa-folder me-1 text-muted"></i> {{ $group ?? 'Ungrouped' }}</td>
                                    </tr>
                                    @foreach ($rows as $l)
                                    <tr>
                                        <td class="ps-3">{{ $l->ledger_name }} <small class="text-muted">[{{ $l->ledger_code }}]</small></td>
                                        <td class="text-muted">{{ $l->parent_group ?? '—' }}</td>
                                        <td class="text-end fw-semibold">{{ number_format($l->net_credit, 2) }}</td>
                                    </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                            <tfoot class="table-warning fw-bold">
                                <tr>
                                    <td colspan="2" class="text-end">Total Liabilities</td>
                                    <td class="text-end">{{ number_format($totals['total_liabilities'], 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-info text-white py-2">
                    <i class="fas fa-landmark me-1"></i> Equity
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" style="font-size: 0.85rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Account</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($equity as $e)
                                <tr>
                                    <td>{{ $e->ledger_name }} <small class="text-muted">[{{ $e->ledger_code }}]</small></td>
                                    <td class="text-end fw-semibold">{{ number_format($e->net_credit, 2) }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="2" class="text-muted text-center py-2">No equity ledgers.</td></tr>
                                @endforelse
                                <tr class="table-light">
                                    <td>
                                        <em>Current Period Result</em>
                                        <br><small class="text-muted">(unclosed income − expense)</small>
                                    </td>
                                    <td class="text-end fw-semibold {{ $current_period_result >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ number_format($current_period_result, 2) }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="table-info fw-bold">
                                <tr>
                                    <td class="text-end">Total Equity + Period Result</td>
                                    <td class="text-end">{{ number_format($totals['total_equity'], 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Bottom totals --}}
    <div class="row g-3 mt-1">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm bg-primary-subtle">
                <div class="card-body py-2 d-flex justify-content-between">
                    <span class="fw-semibold">Total Assets</span>
                    <span class="fw-bold fs-5">{{ number_format($totals['total_assets'], 2) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm bg-info-subtle">
                <div class="card-body py-2 d-flex justify-content-between">
                    <span class="fw-semibold">Total Liabilities + Equity</span>
                    <span class="fw-bold fs-5">{{ number_format($totals['total_liabilities_equity'], 2) }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-2 text-muted small">
        Generated {{ now()->format('d-m-Y H:i') }}
    </div>
</div>

@section('head_meta')
<style>
@media print {
    .card { border: 1px solid #ddd !important; }
    .btn, .form-check, form { display: none !important; }
    .table th, .table td { font-size: 0.75rem !important; padding: 2px 4px !important; }
    .table-active td, .table-light td { background: #f0f0f0 !important; -webkit-print-color-adjust: exact; }
    .table-dark td, .table-dark th { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; }
}
</style>
@endsection
@endsection
