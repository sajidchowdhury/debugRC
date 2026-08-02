@extends('layouts.admin')

@section('title', 'Consolidated Profit & Loss — Remote Center ERP')

@section('content')
<div class="container-fluid py-2">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('admin.consolidation.index') }}">Consolidation</a></li>
                    <li class="breadcrumb-item active">Consolidated Profit &amp; Loss</li>
                </ol>
            </nav>
            <h2 class="h4 mb-1"><i class="fas fa-chart-pie me-2 text-primary"></i> Consolidated Profit &amp; Loss</h2>
            <p class="text-muted mb-0 small">
                From {{ \Carbon\Carbon::parse($meta['from_date'])->format('d-m-Y') }} to {{ \Carbon\Carbon::parse($meta['to_date'])->format('d-m-Y') }}
                @if(!empty($meta['company_id'])) &middot; Company #{{ $meta['company_id'] }} @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-print me-1"></i> Print
            </button>
            <a href="{{ route('admin.consolidation.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.consolidation.consolidated-pnl') }}" class="row g-2 align-items-end">
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
                    <label class="form-label small mb-1">Company</label>
                    <select name="company_id" class="form-select form-select-sm">
                        <option value="">All companies</option>
                        @foreach(\App\Models\ConsolidationCompany::active()->orderBy('company_code')->get() as $c)
                            <option value="{{ $c->id }}" @selected((string) old('company_id', request('company_id', $meta['company_id'] ?? '')) === (string) $c->id)>
                                {{ $c->company_name }} ({{ $c->company_code }})
                            </option>
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
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Total Income</small>
                    <div class="fs-5 fw-bold text-success">{{ number_format($totals['total_income'] ?? 0, 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Total Expenses</small>
                    <div class="fs-5 fw-bold text-danger">{{ number_format($totals['total_expense'] ?? 0, 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Net Income</small>
                    <div class="fs-5 fw-bold {{ ($totals['net_income'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ number_format($totals['net_income'] ?? 0, 2) }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Two-column layout: Income and Expenses --}}
    <div class="row g-3">
        {{-- Income --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-success text-white py-2">
                    <i class="fas fa-arrow-trend-up me-1"></i> Income
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" style="font-size: 0.85rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Account</th>
                                    <th class="text-end">Debit</th>
                                    <th class="text-end">Credit</th>
                                    <th class="text-end">Net Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $incomeGroups = $income->groupBy('parent_group');
                                @endphp
                                @foreach ($incomeGroups as $group => $rows)
                                    <tr class="table-active">
                                        <td colspan="4" class="fw-bold py-1"><i class="fas fa-folder me-1 text-muted"></i> {{ $group ?? 'Ungrouped' }}</td>
                                    </tr>
                                    @foreach ($rows as $inc)
                                    @php
                                        // Income has credit normal balance: credit - debit
                                        $netAmount = ($inc->consolidated_credit ?? $inc->credit ?? 0) - ($inc->consolidated_debit ?? $inc->debit ?? 0);
                                    @endphp
                                    <tr>
                                        <td class="ps-3">{{ $inc->ledger_name ?? $inc->name }} <small class="text-muted">[{{ $inc->ledger_code ?? $inc->code }}]</small></td>
                                        <td class="text-end">
                                            @if(($inc->consolidated_debit ?? $inc->debit ?? 0) > 0.005)
                                                {{ number_format($inc->consolidated_debit ?? $inc->debit, 2) }}
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if(($inc->consolidated_credit ?? $inc->credit ?? 0) > 0.005)
                                                {{ number_format($inc->consolidated_credit ?? $inc->credit, 2) }}
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end fw-semibold {{ $netAmount >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format(abs($netAmount), 2) }}</td>
                                    </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                            <tfoot class="table-success fw-bold">
                                <tr>
                                    <td colspan="3" class="text-end">Total Income</td>
                                    <td class="text-end">{{ number_format($totals['total_income'] ?? 0, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Expenses --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-danger text-white py-2">
                    <i class="fas fa-arrow-trend-down me-1"></i> Expenses
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" style="font-size: 0.85rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Account</th>
                                    <th class="text-end">Debit</th>
                                    <th class="text-end">Credit</th>
                                    <th class="text-end">Net Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $expenseGroups = $expense->groupBy('parent_group');
                                @endphp
                                @foreach ($expenseGroups as $group => $rows)
                                    <tr class="table-active">
                                        <td colspan="4" class="fw-bold py-1"><i class="fas fa-folder me-1 text-muted"></i> {{ $group ?? 'Ungrouped' }}</td>
                                    </tr>
                                    @foreach ($rows as $exp)
                                    @php
                                        // Expenses have debit normal balance: debit - credit
                                        $netAmount = ($exp->consolidated_debit ?? $exp->debit ?? 0) - ($exp->consolidated_credit ?? $exp->credit ?? 0);
                                    @endphp
                                    <tr>
                                        <td class="ps-3">{{ $exp->ledger_name ?? $exp->name }} <small class="text-muted">[{{ $exp->ledger_code ?? $exp->code }}]</small></td>
                                        <td class="text-end">
                                            @if(($exp->consolidated_debit ?? $exp->debit ?? 0) > 0.005)
                                                {{ number_format($exp->consolidated_debit ?? $exp->debit, 2) }}
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if(($exp->consolidated_credit ?? $exp->credit ?? 0) > 0.005)
                                                {{ number_format($exp->consolidated_credit ?? $exp->credit, 2) }}
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end fw-semibold {{ $netAmount >= 0 ? '' : 'text-danger' }}">{{ number_format(abs($netAmount), 2) }}</td>
                                    </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                            <tfoot class="table-danger fw-bold">
                                <tr>
                                    <td colspan="3" class="text-end">Total Expenses</td>
                                    <td class="text-end">{{ number_format($totals['total_expense'] ?? 0, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Net Income section --}}
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-body py-3">
            <div class="row align-items-center">
                <div class="col-md-4 text-center">
                    <div class="text-muted small">Total Income</div>
                    <div class="fw-bold text-success fs-5">{{ number_format($totals['total_income'] ?? 0, 2) }}</div>
                </div>
                <div class="col-md-1 text-center">
                    <span class="text-muted fs-4">−</span>
                </div>
                <div class="col-md-4 text-center">
                    <div class="text-muted small">Total Expenses</div>
                    <div class="fw-bold text-danger fs-5">{{ number_format($totals['total_expense'] ?? 0, 2) }}</div>
                </div>
                <div class="col-md-1 text-center">
                    <span class="text-muted fs-4">=</span>
                </div>
                <div class="col-md-2 text-center">
                    <div class="text-muted small">Net Income</div>
                    <div class="fw-bold fs-5 {{ ($totals['net_income'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ number_format($totals['net_income'] ?? 0, 2) }}
                    </div>
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
