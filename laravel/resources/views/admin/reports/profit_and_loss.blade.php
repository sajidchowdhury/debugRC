@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-chart-pie me-2 text-primary"></i> Profit &amp; Loss Statement</h2>
            <p class="text-muted mb-0 small">
                From {{ \Carbon\Carbon::parse($meta['from_date'])->format('d-m-Y') }} to {{ \Carbon\Carbon::parse($meta['to_date'])->format('d-m-Y') }}
                @if($meta['branch_id']) &middot; Branch #{{ $meta['branch_id'] }} @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.profitAndLoss', array_merge(request()->query(), ['export' => 'csv'])) }}"
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
            <form method="GET" action="{{ route('admin.reports.profitAndLoss') }}" class="row g-2 align-items-end">
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
                        @foreach (\App\Models\Branch::active()->orderBy('branch_name')->get() as $b)
                            <option value="{{ $b->id }}" @selected((int) old('branch_id', request('branch_id', $meta['branch_id'] ?? '')) === $b->id)>{{ $b->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- KPI summary cards --}}
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Revenue</small>
                    <div class="fs-5 fw-bold text-primary">{{ number_format($totals['revenue'], 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Gross Profit <span class="text-muted">({{ $totals['gross_margin_pct'] }}%)</span></small>
                    <div class="fs-5 fw-bold {{ $totals['gross_profit'] >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($totals['gross_profit'], 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Operating Income</small>
                    <div class="fs-5 fw-bold {{ $totals['operating_income'] >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($totals['operating_income'], 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Net Income <span class="text-muted">({{ $totals['net_margin_pct'] }}%)</span></small>
                    <div class="fs-5 fw-bold {{ $totals['net_income'] >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($totals['net_income'], 2) }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Main P&L statement — Xero multi-step format --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0" style="font-size: 0.85rem;">
                    <thead>
                        <tr class="table-dark">
                            <th style="min-width: 300px;">Description</th>
                            <th class="text-end" style="min-width: 150px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $sortedSections = collect($sections)->sortBy('sort');
                            $isSubtotal = fn($key) => in_array($key, ['gross_profit', 'operating_income', 'net_income_before_tax', 'net_income']);
                        @endphp

                        @foreach ($sortedSections as $key => $section)
                            @php
                                $isSub = $isSubtotal($key);
                                $isRevenue = $key === 'revenue';
                                $isCogs = $key === 'cost_of_sales';
                                $isExpense = in_array($key, ['operating_expenses', 'finance_costs']);
                                $isFinal = $key === 'net_income';
                            @endphp

                            @if ($isSub)
                                {{-- Subtotal row --}}
                                <tr class="table-light">
                                    <td class="fw-bold py-2">
                                        @if($key === 'gross_profit')
                                            <strong>Gross Profit</strong>
                                            <small class="text-muted ms-1">(Revenue − COGS)</small>
                                        @elseif($key === 'operating_income')
                                            <strong>Operating Income</strong>
                                            <small class="text-muted ms-1">(Gross Profit − OpEx)</small>
                                        @elseif($key === 'net_income_before_tax')
                                            <strong>Net Income Before Tax</strong>
                                        @else
                                            <strong>Net Income</strong>
                                        @endif
                                    </td>
                                    <td class="text-end fw-bold fs-6 {{ $section['total'] >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ number_format(abs($section['total']), 2) }}
                                    </td>
                                </tr>
                            @else
                                {{-- Section header --}}
                                <tr class="table-active">
                                    <td colspan="2" class="fw-bold py-1">
                                        @if($isExpense)
                                            Less:
                                        @endif
                                        {{ $section['label'] }}
                                    </td>
                                </tr>

                                {{-- Detail rows --}}
                                @forelse ($section['rows'] as $row)
                                    <tr>
                                        <td class="ps-4">
                                            {{ $row->ledger_name }}
                                            <small class="text-muted ms-1">[{{ $row->ledger_code }}]</small>
                                        </td>
                                        <td class="text-end">
                                            {{ number_format(abs($row->net_amount), 2) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="ps-4 text-muted">— No activity —</td>
                                        <td class="text-end text-muted">0.00</td>
                                    </tr>
                                @endforelse

                                {{-- Section total --}}
                                <tr class="fw-semibold">
                                    <td class="ps-2">Total {{ $section['label'] }}</td>
                                    <td class="text-end {{ $section['total'] >= 0 ? '' : 'text-danger' }}">
                                        {{ number_format(abs($section['total']), 2) }}
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Formula summary --}}
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-body py-2">
            <div class="row text-center small">
                <div class="col-md-3">
                    <span class="text-muted">Revenue</span><br>
                    <strong>{{ number_format($totals['revenue'], 2) }}</strong>
                </div>
                <div class="col-md-1 d-flex align-items-center justify-content-center">
                    <span class="text-muted">−</span>
                </div>
                <div class="col-md-2">
                    <span class="text-muted">COGS</span><br>
                    <strong>{{ number_format($totals['cogs'], 2) }}</strong>
                </div>
                <div class="col-md-1 d-flex align-items-center justify-content-center">
                    <span class="text-muted">−</span>
                </div>
                <div class="col-md-2">
                    <span class="text-muted">OpEx</span><br>
                    <strong>{{ number_format($totals['operating_expenses'], 2) }}</strong>
                </div>
                <div class="col-md-1 d-flex align-items-center justify-content-center">
                    <span class="text-muted">−</span>
                </div>
                <div class="col-md-2">
                    <span class="text-muted">Finance</span><br>
                    <strong>{{ number_format($totals['finance_costs'], 2) }}</strong>
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
