@extends('layouts.admin')

@section('content')
<div class="row mb-3">
    <div class="col-12">
        <h4 class="mb-0">
            <i class="fas fa-percent text-success me-2"></i>
            {{ $meta['title'] ?? 'Gross Margin Analysis (CTE)' }}
        </h4>
        <small class="text-muted">
            {{ $meta['from_date'] ?? '' }} to {{ $meta['to_date'] ?? '' }}
            &middot; Source: <span class="badge bg-info">{{ $meta['source'] ?? 'cte' }}</span>
        </small>
    </div>
</div>

{{-- Filter --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('admin.reports.grossMarginCte') }}" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="from_date" class="form-control form-control-sm" value="{{ $meta['from_date'] ?? '' }}">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="to_date" class="form-control form-control-sm" value="{{ $meta['to_date'] ?? '' }}">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">Branch</label>
                <select name="branch_id" class="form-select form-select-sm" style="min-width:160px">
                    <option value="">All Branches</option>
                    @foreach($branches ?? [] as $b)
                    <option value="{{ $b->id }}" {{ ($meta['branch_id'] ?? '') == $b->id ? 'selected' : '' }}>{{ $b->branch_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync-alt me-1"></i>Run</button>
            </div>
        </form>
    </div>
</div>

{{-- Grand Totals --}}
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-start border-4 border-primary h-100">
            <div class="card-body text-center">
                <div class="text-muted small">Total Revenue</div>
                <div class="h5 mb-0">৳{{ number_format($totals['total_revenue'] ?? 0, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-start border-4 border-danger h-100">
            <div class="card-body text-center">
                <div class="text-muted small">Total COGS</div>
                <div class="h5 mb-0">৳{{ number_format($totals['total_cogs'] ?? 0, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-start border-4 border-success h-100">
            <div class="card-body text-center">
                <div class="text-muted small">Gross Profit</div>
                <div class="h5 mb-0 fw-bold">৳{{ number_format($totals['total_gross_profit'] ?? 0, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-start border-4 {{ ($totals['overall_margin_pct'] ?? 0) >= 20 ? 'border-success' : 'border-warning' }} h-100">
            <div class="card-body text-center">
                <div class="text-muted small">Overall Margin</div>
                <div class="h3 mb-0 fw-bold {{ ($totals['overall_margin_pct'] ?? 0) >= 20 ? 'text-success' : 'text-warning' }}">{{ $totals['overall_margin_pct'] ?? 0 }}%</div>
            </div>
        </div>
    </div>
</div>

{{-- Per-Product Margin --}}
<div class="card mb-4">
    <div class="card-header py-2"><i class="fas fa-box me-1"></i>Product-Level Margin</div>
    <div class="card-body p-0" style="max-height:400px;overflow-y:auto">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light sticky-top">
                <tr>
                    <th>Code</th><th>Product</th><th class="text-end">Qty</th>
                    <th class="text-end">Revenue</th><th class="text-end">COGS</th>
                    <th class="text-end">Gross Profit</th><th class="text-end fw-bold">Margin %</th>
                </tr>
            </thead>
            <tbody>
            @foreach(($product_margin ?? collect()) as $pm)
            <tr>
                <td>{{ $pm['product_code'] ?? '' }}</td>
                <td>{{ $pm['product_name'] ?? '' }}</td>
                <td class="text-end">{{ number_format($pm['total_qty'] ?? 0) }}</td>
                <td class="text-end">৳{{ number_format($pm['total_revenue'] ?? 0, 2) }}</td>
                <td class="text-end">৳{{ number_format($pm['total_cogs'] ?? 0, 2) }}</td>
                <td class="text-end {{ ($pm['gross_profit'] ?? 0) < 0 ? 'text-danger' : 'text-success' }}">৳{{ number_format($pm['gross_profit'] ?? 0, 2) }}</td>
                <td class="text-end fw-bold {{ ($pm['margin_pct'] ?? 0) < 10 ? 'text-danger' : '' }}">{{ $pm['margin_pct'] ?? 0 }}%</td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Per-Invoice Margin --}}
<div class="card mb-4">
    <div class="card-header py-2 d-flex justify-content-between">
        <span><i class="fas fa-file-invoice me-1"></i>Invoice-Level Margin</span>
        <span class="badge bg-secondary">{{ ($invoice_margin ?? collect())->count() }} invoices</span>
    </div>
    <div class="card-body p-0" style="max-height:500px;overflow-y:auto">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light sticky-top">
                <tr>
                    <th>Invoice</th><th>Date</th><th>Customer</th>
                    <th class="text-end">Revenue</th><th class="text-end">COGS</th>
                    <th class="text-end">Gross Profit</th><th class="text-end fw-bold">Margin %</th>
                </tr>
            </thead>
            <tbody>
            @foreach(($invoice_margin ?? collect()) as $im)
            <tr>
                <td>{{ $im['invoice_code'] ?? '' }}</td>
                <td>{{ $im['invoice_date'] ?? '' }}</td>
                <td>{{ $im['customer_name'] ?? '' }}</td>
                <td class="text-end">৳{{ number_format($im['total_revenue'] ?? 0, 2) }}</td>
                <td class="text-end">৳{{ number_format($im['total_cogs'] ?? 0, 2) }}</td>
                <td class="text-end {{ ($im['gross_profit'] ?? 0) < 0 ? 'text-danger' : 'text-success' }}">৳{{ number_format($im['gross_profit'] ?? 0, 2) }}</td>
                <td class="text-end fw-bold {{ ($im['margin_pct'] ?? 0) < 10 ? 'text-danger' : '' }}">{{ $im['margin_pct'] ?? 0 }}%</td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="text-center mb-3">
    <span class="badge bg-info">
        <i class="fas fa-database me-1"></i>Powered by PostgreSQL CTE — Per-item COGS via stock_transactions, not single-challan approximation
    </span>
</div>
@endsection
