@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-chart-line me-2 text-primary"></i> Branch P&amp;L Report</h2>
            <p class="text-muted mb-0 small">
                Consolidated view of inter-branch demand, sales mix, profit/loss, and outstanding due.
                Branch B (subject) is shown below; pick Branch A (supplier, "view as") via the dropdown.
            </p>
        </div>
        <div class="d-flex gap-2">
            @if(!empty($report['per_demand']))
            <a href="{{ route('admin.branches.pnl.export', ['branch' => $branchB->id, 'view_as' => $viewAs, 'from' => $from, 'to' => $to]) }}"
               class="btn btn-outline-success btn-sm">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </a>
            @endif
            <a href="{{ route('admin.branches.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Branches
            </a>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.branches.pnl', $branchB->id) }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1">View as (Branch A — supplier)</label>
                    <select name="view_as" class="form-select form-select-sm">
                        <option value="">— select supplier branch —</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) $viewAs === (int) $b->id)>{{ $b->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">From date</label>
                    <input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">To date</label>
                    <input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted">
                        <strong>Subject branch (B):</strong> {{ $branchB->branch_name }}
                        @if (!empty($branchA->branch_name))
                            <span class="ms-2"><strong>Supplier (A):</strong> {{ $branchA->branch_name }}</span>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if (!empty($error))
        <div class="alert alert-warning py-2">
            <i class="fas fa-exclamation-triangle me-1"></i> {{ $error }}
        </div>
    @endif

    @if (empty($error) && !empty($report['per_demand']))
        {{-- Summary cards --}}
        <div class="row g-2 mb-3">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body py-2">
                        <div class="small text-muted">Demanded Value</div>
                        <div class="h5 mb-0">Tk {{ number_format($report['demand_summary']['total_demanded_value'], 2) }}</div>
                        <div class="small text-muted">{{ $report['demand_summary']['demand_count'] }} demand(s) · {{ number_format($report['demand_summary']['total_demanded_qty'], 3) }} units</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body py-2">
                        <div class="small text-muted">Outstanding Due</div>
                        <div class="h5 mb-0 {{ $report['outstanding_due'] > 0 ? 'text-danger' : 'text-success' }}">
                            Tk {{ number_format($report['outstanding_due'], 2) }}
                        </div>
                        <div class="small text-muted">Settled: Tk {{ number_format($report['demand_summary']['settled_amount'], 2) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body py-2">
                        <div class="small text-muted">Net P&amp;L (Branch B)</div>
                        <div class="h5 mb-0 {{ $report['sales_summary']['net_pl'] >= 0 ? 'text-success' : 'text-danger' }}">
                            Tk {{ number_format($report['sales_summary']['net_pl'], 2) }}
                        </div>
                        <div class="small text-muted">Revenue Tk {{ number_format($report['sales_summary']['total_revenue'], 2) }} · Cost Tk {{ number_format($report['sales_summary']['total_cost'], 2) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body py-2">
                        <div class="small text-muted">Below-Min Overrides</div>
                        <div class="h5 mb-0 {{ $report['sales_summary']['override_count'] > 0 ? 'text-warning' : '' }}">
                            {{ $report['sales_summary']['override_count'] }}
                        </div>
                        <div class="small text-muted">
                            Qty: {{ number_format($report['sales_summary']['qty_below_min'], 3) }} /
                            Min: {{ number_format($report['sales_summary']['qty_at_min'], 3) }} /
                            Default: {{ number_format($report['sales_summary']['qty_at_default'], 3) }} /
                            Max: {{ number_format($report['sales_summary']['qty_at_max'], 3) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Per-demand table --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-light py-2">
                <strong>Per-Demand Breakdown</strong>
                <span class="text-muted small">{{ count($report['per_demand']) }} demand(s)</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Demand Code</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th class="text-end">Demanded Qty</th>
                                <th class="text-end">Demanded Value</th>
                                <th class="text-end">Sold Qty</th>
                                <th class="text-end">Revenue</th>
                                <th class="text-end">Cost</th>
                                <th class="text-end">Net P&amp;L</th>
                                <th class="text-end">Below-Min</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['per_demand'] as $row)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.branch-demands.show', $row['demand_id']) }}">
                                            {{ $row['demand_code'] }}
                                        </a>
                                    </td>
                                    <td>{{ $row['demand_date'] }}</td>
                                    <td>
                                        <span class="badge bg-secondary">{{ $row['demand_status'] }}</span>
                                    </td>
                                    <td class="text-end">{{ number_format($row['demanded_qty'], 3) }}</td>
                                    <td class="text-end">{{ number_format($row['demanded_value'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['sold_qty'], 3) }}</td>
                                    <td class="text-end">{{ number_format($row['revenue'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['cost'], 2) }}</td>
                                    <td class="text-end {{ $row['pl'] >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ number_format($row['pl'], 2) }}
                                    </td>
                                    <td class="text-end">
                                        @if ($row['override_count'] > 0)
                                            <span class="badge bg-warning text-dark">{{ $row['override_count'] }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <a href="{{ route('admin.branch-demands.pnl', $row['demand_id']) }}"
                                           class="btn btn-outline-primary btn-xs" title="Drilldown">
                                            <i class="fas fa-search-plus"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light fw-semibold">
                            <tr>
                                <td colspan="3">TOTAL</td>
                                <td class="text-end">{{ number_format($report['demand_summary']['total_demanded_qty'], 3) }}</td>
                                <td class="text-end">{{ number_format($report['demand_summary']['total_demanded_value'], 2) }}</td>
                                <td class="text-end">{{ number_format($report['sales_summary']['total_sold_qty'], 3) }}</td>
                                <td class="text-end">{{ number_format($report['sales_summary']['total_revenue'], 2) }}</td>
                                <td class="text-end">{{ number_format($report['sales_summary']['total_cost'], 2) }}</td>
                                <td class="text-end {{ $report['sales_summary']['net_pl'] >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ number_format($report['sales_summary']['net_pl'], 2) }}
                                </td>
                                <td class="text-end">{{ $report['sales_summary']['override_count'] }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    @elseif (empty($error))
        <div class="alert alert-info py-2">
            <i class="fas fa-info-circle me-1"></i>
            No demands found between the selected branches for the running fiscal year.
            Try a different "View as" branch or date range.
        </div>
    @endif

    {{-- Footer note on FY scoping --}}
    <div class="mt-3 small text-muted">
        <i class="fas fa-shield-alt me-1"></i>
        This report is scoped to the running fiscal year. Closed FY data is invisible
        (global scope + partition detach). The outstanding-due figure is perpetual
        (carries across FY boundaries via <code>branch_ledger.running_balance</code>).
    </div>
</div>
@endsection
