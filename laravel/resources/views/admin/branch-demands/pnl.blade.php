@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1">
                <i class="fas fa-search-plus me-2 text-primary"></i>
                Demand P&amp;L Drilldown — {{ $demand->demand_code }}
            </h2>
            <p class="text-muted mb-0 small">
                {{ $demand->from_branch_name }} (requester/seller) ← supplied by {{ $demand->to_branch_name }}
                · dated {{ $demand->demand_date }}
                · status <span class="badge bg-secondary">{{ $demand->status }}</span>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.branch-demands.show', $demand->id) }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Demand detail
            </a>
        </div>
    </div>

    {{-- Summary cards --}}
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Demanded</div>
                    <div class="h5 mb-0">{{ number_format($summary['total_demanded_qty'], 3) }} units</div>
                    <div class="small text-muted">Tk {{ number_format($summary['total_demanded_value'], 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Sold</div>
                    <div class="h5 mb-0">{{ number_format($summary['total_sold_qty'], 3) }} units</div>
                    <div class="small text-muted">{{ $summary['consumed_pct'] }}% of demanded</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Net P&amp;L</div>
                    <div class="h5 mb-0 {{ $summary['net_pl'] >= 0 ? 'text-success' : 'text-danger' }}">
                        Tk {{ number_format($summary['net_pl'], 2) }}
                    </div>
                    <div class="small text-muted">Revenue Tk {{ number_format($summary['total_revenue'], 2) }} · Cost Tk {{ number_format($summary['total_cost'], 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Outstanding</div>
                    <div class="h5 mb-0 {{ $summary['outstanding'] > 0 ? 'text-danger' : 'text-success' }}">
                        Tk {{ number_format($summary['outstanding'], 2) }}
                    </div>
                    <div class="small text-muted">Demand total Tk {{ number_format($demand->total_value ?? 0, 2) }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Sale-line detail table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light py-2">
            <strong>Sale-Line Detail</strong>
            <span class="text-muted small">{{ count($sale_lines) }} line(s)</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Product</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Cost</th>
                            <th class="text-end">P&amp;L</th>
                            <th>Classification</th>
                            <th>Approver / Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sale_lines as $line)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.sales-invoices.show', $line['invoice_id']) }}">
                                        {{ $line['invoice_code'] }}
                                    </a>
                                </td>
                                <td>{{ $line['invoice_date'] }}</td>
                                <td>{{ $line['product_name'] ?? '(product #' . $line['product_id'] . ')' }}</td>
                                <td class="text-end">{{ number_format($line['qty'], 3) }}</td>
                                <td class="text-end">{{ number_format($line['rate'], 2) }}</td>
                                <td class="text-end">{{ $line['cost_rate'] !== null ? number_format($line['cost_rate'], 4) : '—' }}</td>
                                <td class="text-end">
                                    @php
                                        $linePl = (float) $line['qty'] * ((float) $line['rate'] - (float) ($line['cost_rate'] ?? 0));
                                    @endphp
                                    <span class="{{ $linePl >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ number_format($linePl, 2) }}
                                    </span>
                                </td>
                                <td>
                                    @if ($line['price_classification'] === 'below_min')
                                        <span class="badge bg-warning text-dark">below_min</span>
                                    @elseif ($line['price_classification'] === 'min')
                                        <span class="badge bg-info text-dark">min</span>
                                    @elseif ($line['price_classification'] === 'default')
                                        <span class="badge bg-primary">default</span>
                                    @elseif ($line['price_classification'] === 'max')
                                        <span class="badge bg-success">max</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($line['below_min_override_id'])
                                        <div class="small">
                                            <i class="fas fa-user-shield me-1 text-muted"></i>
                                            {{ $line['approver_name'] ?? '(unknown)' }}
                                        </div>
                                        <div class="small text-muted fst-italic">
                                            "{{ $line['override_reason'] ?? '(no reason recorded)' }}"
                                        </div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-muted text-center py-3">No sale lines linked to this demand yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Footer note --}}
    <div class="mt-3 small text-muted">
        <i class="fas fa-link me-1"></i>
        Sale lines are linked to this demand via FIFO (<code>branch_demand_item_id</code>).
        Multi-demand sales are split into multiple rows transparently — each row is
        attributed to a single demand item for clean P&amp;L.
    </div>
</div>
@endsection
