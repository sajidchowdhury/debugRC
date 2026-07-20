@extends('layouts.admin')

@section('content')
<div class="row mb-3">
    <div class="col-12">
        <h4 class="mb-0">
            <i class="fas fa-chart-line text-primary me-2"></i>
            {{ $meta['title'] ?? "Today's Summary" }}
        </h4>
        <small class="text-muted">
            Date: {{ $meta['date'] ?? now()->toDateString() }}
            @if($meta['branch_id'] ?? null) &middot; Branch: {{ $meta['branch_id'] }} @endif
            &middot; Source: <span class="badge bg-info">{{ $meta['source'] ?? 'cte' }}</span>
        </small>
    </div>
</div>

{{-- Filter form --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('admin.reports.todaySummaryCte') }}" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small mb-1">Date</label>
                <input type="date" name="date" class="form-control form-control-sm" value="{{ $meta['date'] ?? now()->toDateString() }}">
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
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync-alt me-1"></i>Refresh</button>
            </div>
        </form>
    </div>
</div>

{{-- KPI Cards --}}
<div class="row g-3 mb-4">
    {{-- Today --}}
    <div class="col-md-3">
        <div class="card border-start border-4 border-primary h-100">
            <div class="card-body">
                <div class="text-muted small">Today's Revenue</div>
                <div class="h4 mb-0">৳{{ number_format($today['total_sales'] ?? 0, 2) }}</div>
                <div class="small text-muted">{{ $today['invoice_count'] ?? 0 }} invoices</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-start border-4 border-success h-100">
            <div class="card-body">
                <div class="text-muted small">MTD Revenue</div>
                <div class="h4 mb-0">৳{{ number_format($mtd['total_sales'] ?? 0, 2) }}</div>
                <div class="small text-muted">{{ $mtd['invoice_count'] ?? 0 }} invoices</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-start border-4 border-info h-100">
            <div class="card-body">
                <div class="text-muted small">MTD Collection</div>
                <div class="h4 mb-0">৳{{ number_format($mtd['total_collection'] ?? 0, 2) }}</div>
                <div class="small text-muted">Rate: {{ $mtd['collection_rate'] ?? 0 }}%</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-start border-4 border-danger h-100">
            <div class="card-body">
                <div class="text-muted small">Total Outstanding</div>
                <div class="h4 mb-0">৳{{ number_format($outstanding['total_outstanding'] ?? 0, 2) }}</div>
                <div class="small text-muted">MTD Due: ৳{{ number_format($mtd['total_due'] ?? 0, 2) }}</div>
            </div>
        </div>
    </div>
</div>

{{-- Growth + Pending --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header py-2"><i class="fas fa-arrow-trend-up me-1"></i>Revenue Growth</div>
            <div class="card-body text-center">
                <div class="h2 {{ ($growth['revenue_growth_pct'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                    {{ ($growth['revenue_growth_pct'] ?? 0) >= 0 ? '+' : '' }}{{ $growth['revenue_growth_pct'] ?? 0 }}%
                </div>
                <div class="small text-muted">vs Previous Month (৳{{ number_format($growth['prev_month_sales'] ?? 0, 2) }})</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header py-2"><i class="fas fa-clock me-1"></i>Pending Operations</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td>Draft Invoices</td><td class="text-end fw-bold">{{ $pending['draft_count'] ?? 0 }}</td></tr>
                    <tr><td>Pending Godown Prep</td><td class="text-end fw-bold">{{ $pending['pending_godown'] ?? 0 }}</td></tr>
                    <tr><td>Pending Challan Issue</td><td class="text-end fw-bold">{{ $pending['pending_challan'] ?? 0 }}</td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header py-2"><i class="fas fa-exclamation-triangle me-1"></i>AR Aging Summary</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td>0-30 days</td><td class="text-end">৳{{ number_format($ar_aging['bucket_0_30'] ?? 0, 2) }}</td></tr>
                    <tr><td>31-60 days</td><td class="text-end">৳{{ number_format($ar_aging['bucket_31_60'] ?? 0, 2) }}</td></tr>
                    <tr><td>61-90 days</td><td class="text-end">৳{{ number_format($ar_aging['bucket_61_90'] ?? 0, 2) }}</td></tr>
                    <tr><td>90+ days</td><td class="text-end text-danger fw-bold">৳{{ number_format($ar_aging['bucket_90_plus'] ?? 0, 2) }}</td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Top Customers + Products --}}
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header py-2"><i class="fas fa-users me-1"></i>Top 5 Customers (MTD)</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Customer</th><th class="text-end">Invoices</th><th class="text-end">Revenue</th><th class="text-end">Due</th></tr></thead>
                    <tbody>
                    @foreach($top_customers ?? [] as $c)
                    <tr>
                        <td>{{ $c['customer_name'] ?? '' }}</td>
                        <td class="text-end">{{ $c['invoice_count'] ?? 0 }}</td>
                        <td class="text-end">৳{{ number_format($c['total_revenue'] ?? 0, 2) }}</td>
                        <td class="text-end {{ ($c['total_due'] ?? 0) > 0 ? 'text-danger' : '' }}">৳{{ number_format($c['total_due'] ?? 0, 2) }}</td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header py-2"><i class="fas fa-box me-1"></i>Top 5 Products (MTD)</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Product</th><th class="text-end">Qty Sold</th><th class="text-end">Revenue</th></tr></thead>
                    <tbody>
                    @foreach($top_products ?? [] as $p)
                    <tr>
                        <td>{{ ($p['product_code'] ?? '') . ' - ' . ($p['product_name'] ?? '') }}</td>
                        <td class="text-end">{{ number_format($p['qty_sold'] ?? 0) }}</td>
                        <td class="text-end">৳{{ number_format($p['revenue'] ?? 0, 2) }}</td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Branch Revenue --}}
@if(count($branch_revenue ?? []) > 0)
<div class="card mb-4">
    <div class="card-header py-2"><i class="fas fa-building me-1"></i>Branch Revenue (MTD)</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>Branch</th><th class="text-end">Invoices</th><th class="text-end">Revenue</th></tr></thead>
            <tbody>
            @foreach($branch_revenue ?? [] as $br)
            <tr>
                <td>{{ $br['branch_name'] ?? '' }}</td>
                <td class="text-end">{{ $br['invoice_count'] ?? 0 }}</td>
                <td class="text-end">৳{{ number_format($br['revenue'] ?? 0, 2) }}</td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- CTE badge --}}
<div class="text-center mb-3">
    <span class="badge bg-info">
        <i class="fas fa-database me-1"></i>Powered by PostgreSQL CTE — Single query replaces 6+ SQL roundtrips
    </span>
</div>
@endsection
