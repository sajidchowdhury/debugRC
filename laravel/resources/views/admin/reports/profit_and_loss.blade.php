@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-chart-pie me-2 text-primary"></i> Profit &amp; Loss Statement</h2>
            <p class="text-muted mb-0 small">Income − expense by ledger nature, for the period {{ \Carbon\Carbon::parse($meta['from_date'])->format('d M Y') }} → {{ \Carbon\Carbon::parse($meta['to_date'])->format('d M Y') }}.</p>
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
                    <label class="form-label small mb-1">Branch (optional)</label>
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

    {{-- Summary cards --}}
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Revenue</small>
                    <div class="fs-5 fw-bold text-success">Tk {{ number_format($totals['revenue'], 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Cost of Goods Sold</small>
                    <div class="fs-5 fw-bold text-danger">Tk {{ number_format($totals['cogs'], 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Gross Profit</small>
                    <div class="fs-5 fw-bold {{ $totals['gross_profit'] >= 0 ? 'text-success' : 'text-danger' }}">Tk {{ number_format($totals['gross_profit'], 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Net Profit</small>
                    <div class="fs-5 fw-bold {{ $totals['net_profit'] >= 0 ? 'text-success' : 'text-danger' }}">Tk {{ number_format($totals['net_profit'], 2) }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Sections --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            @foreach (collect($sections)->sortBy('sort') as $key => $section)
                @php
                    $isRevenue = $key === 'revenue';
                    $isCogs    = $key === 'cost_of_sales';
                @endphp
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-2">
                        <h3 class="h6 mb-0"><i class="fas fa-tag me-1 text-muted"></i> {{ $section['label'] }}</h3>
                        <span class="fw-bold {{ $section['total'] >= 0 ? 'text-success' : 'text-danger' }}">Tk {{ number_format($section['total'], 2) }}</span>
                    </div>
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="120">Code</th>
                                <th>Ledger</th>
                                <th width="160">Nature</th>
                                <th class="text-end" width="140">Debit</th>
                                <th class="text-end" width="140">Credit</th>
                                <th class="text-end" width="140">Net amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($section['rows'] as $row)
                                <tr>
                                    <td><code>{{ $row->ledger_code }}</code></td>
                                    <td>{{ $row->ledger_name }}</td>
                                    <td><small class="text-muted">{{ $row->ledger_nature }}</small></td>
                                    <td class="text-end">{{ number_format($row->debit, 2) }}</td>
                                    <td class="text-end">{{ number_format($row->credit, 2) }}</td>
                                    <td class="text-end fw-semibold">{{ number_format($row->net_amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-muted text-center py-2">No activity in this section.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($isRevenue && !empty($sections['cost_of_sales']))
                    <div class="alert alert-info d-flex justify-content-between align-items-center py-2">
                        <span><i class="fas fa-arrow-up me-1"></i> Gross Profit (Revenue − COGS)</span>
                        <span class="fw-bold fs-5">Tk {{ number_format($totals['gross_profit'], 2) }}</span>
                    </div>
                @endif
            @endforeach

            {{-- Net profit highlighted --}}
            <div class="alert {{ $totals['net_profit'] >= 0 ? 'alert-success' : 'alert-danger' }} d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="fas fa-money-bill-trend-up me-1"></i> Net Profit for the period</span>
                <span class="fw-bold fs-4">Tk {{ number_format($totals['net_profit'], 2) }}</span>
            </div>
        </div>
    </div>
</div>
@endsection
