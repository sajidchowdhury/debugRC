@extends('layouts.admin')

@section('content')
<div class="row mb-3">
    <div class="col-12">
        <h4 class="mb-0">
            <i class="fas fa-clock text-danger me-2"></i>
            {{ $meta['title'] ?? 'Receivable Aging (CTE)' }}
        </h4>
        <small class="text-muted">
            As of: {{ $meta['as_of_date'] ?? now()->toDateString() }}
            @if($meta['branch_id'] ?? null) &middot; Branch: {{ $meta['branch_id'] }} @endif
            &middot; Source: <span class="badge bg-info">{{ $meta['source'] ?? 'cte' }}</span>
        </small>
    </div>
</div>

{{-- Filter form --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('admin.reports.arAgingCte') }}" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small mb-1">As of Date</label>
                <input type="date" name="as_of_date" class="form-control form-control-sm" value="{{ $meta['as_of_date'] ?? now()->toDateString() }}">
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

{{-- Aging Summary Cards --}}
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <div class="card border-start border-4 border-success h-100">
            <div class="card-body text-center">
                <div class="text-muted small">0-30 days</div>
                <div class="h5 mb-0">৳{{ number_format($totals['bucket_0_30'] ?? 0, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-start border-4 border-info h-100">
            <div class="card-body text-center">
                <div class="text-muted small">31-60 days</div>
                <div class="h5 mb-0">৳{{ number_format($totals['bucket_31_60'] ?? 0, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-start border-4 border-warning h-100">
            <div class="card-body text-center">
                <div class="text-muted small">61-90 days</div>
                <div class="h5 mb-0">৳{{ number_format($totals['bucket_61_90'] ?? 0, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-start border-4 border-danger h-100">
            <div class="card-body text-center">
                <div class="text-muted small">90+ days</div>
                <div class="h5 mb-0 text-danger">৳{{ number_format($totals['bucket_90_plus'] ?? 0, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-start border-4 border-primary h-100">
            <div class="card-body text-center">
                <div class="text-muted small">Total Receivable</div>
                <div class="h5 mb-0 fw-bold">৳{{ number_format($totals['total_receivable'] ?? 0, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-start border-4 {{ ($checks['matches_gl'] ?? false) ? 'border-success' : 'border-danger' }} h-100">
            <div class="card-body text-center">
                <div class="text-muted small">GL AR Control</div>
                <div class="h5 mb-0">৳{{ number_format($totals['gl_ar_control'] ?? 0, 2) }}</div>
                <span class="badge {{ ($checks['matches_gl'] ?? false) ? 'bg-success' : 'bg-danger' }}">
                    {{ ($checks['matches_gl'] ?? false) ? 'MATCHES' : 'OUT OF BALANCE' }}
                </span>
            </div>
        </div>
    </div>
</div>

{{-- Customer Aging Table --}}
<div class="card mb-4">
    <div class="card-header py-2 d-flex justify-content-between">
        <span><i class="fas fa-users me-1"></i>Customer Aging Detail</span>
        <span class="badge bg-secondary">{{ ($data ?? collect())->count() }} customers</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Code</th>
                    <th>Customer</th>
                    <th>Mobile</th>
                    <th>Branch</th>
                    <th class="text-end">0-30</th>
                    <th class="text-end">31-60</th>
                    <th class="text-end">61-90</th>
                    <th class="text-end">90+</th>
                    <th class="text-end fw-bold">Total</th>
                </tr>
            </thead>
            <tbody>
            @foreach(($data ?? collect()) as $row)
            <tr>
                <td>{{ $row['customer_code'] ?? '' }}</td>
                <td>{{ $row['customer_name'] ?? '' }}</td>
                <td>{{ $row['mobile'] ?? '' }}</td>
                <td>{{ $row['branch_name'] ?? '' }}</td>
                <td class="text-end">৳{{ number_format($row['bucket_0_30'] ?? 0, 2) }}</td>
                <td class="text-end">৳{{ number_format($row['bucket_31_60'] ?? 0, 2) }}</td>
                <td class="text-end">৳{{ number_format($row['bucket_61_90'] ?? 0, 2) }}</td>
                <td class="text-end {{ ($row['bucket_90_plus'] ?? 0) > 0 ? 'text-danger fw-bold' : '' }}">৳{{ number_format($row['bucket_90_plus'] ?? 0, 2) }}</td>
                <td class="text-end fw-bold">৳{{ number_format($row['total_receivable'] ?? 0, 2) }}</td>
            </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light">
                <tr class="fw-bold">
                    <td colspan="4">TOTAL</td>
                    <td class="text-end">৳{{ number_format($totals['bucket_0_30'] ?? 0, 2) }}</td>
                    <td class="text-end">৳{{ number_format($totals['bucket_31_60'] ?? 0, 2) }}</td>
                    <td class="text-end">৳{{ number_format($totals['bucket_61_90'] ?? 0, 2) }}</td>
                    <td class="text-end {{ ($totals['bucket_90_plus'] ?? 0) > 0 ? 'text-danger' : '' }}">৳{{ number_format($totals['bucket_90_plus'] ?? 0, 2) }}</td>
                    <td class="text-end">৳{{ number_format($totals['total_receivable'] ?? 0, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

{{-- Overdue Invoices --}}
@if(($overdue_invoices ?? collect())->count() > 0)
<div class="card mb-4">
    <div class="card-header py-2"><i class="fas fa-exclamation-circle text-danger me-1"></i>Top Overdue Invoices (>30 days)</div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Branch</th>
                    <th class="text-end">Days Overdue</th>
                    <th class="text-end">Amount Due</th>
                </tr>
            </thead>
            <tbody>
            @foreach($overdue_invoices as $inv)
            <tr>
                <td><a href="{{ route('admin.sales-invoices.show', $inv['id'] ?? 0) }}">{{ $inv['invoice_code'] ?? '' }}</a></td>
                <td>{{ $inv['invoice_date'] ?? '' }}</td>
                <td>{{ $inv['customer_name'] ?? '' }}</td>
                <td>{{ $inv['branch_name'] ?? '' }}</td>
                <td class="text-end {{ ($inv['days_overdue'] ?? 0) > 60 ? 'text-danger fw-bold' : '' }}">{{ $inv['days_overdue'] ?? 0 }}</td>
                <td class="text-end">৳{{ number_format($inv['due_amount'] ?? 0, 2) }}</td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- Aging by Branch --}}
@if(($aging_by_branch ?? collect())->count() > 1)
<div class="card mb-4">
    <div class="card-header py-2"><i class="fas fa-building me-1"></i>Aging by Branch</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Branch</th>
                    <th class="text-end">0-30</th>
                    <th class="text-end">31-60</th>
                    <th class="text-end">61-90</th>
                    <th class="text-end">90+</th>
                    <th class="text-end fw-bold">Total</th>
                </tr>
            </thead>
            <tbody>
            @foreach($aging_by_branch as $abr)
            <tr>
                <td>{{ $abr['branch_name'] ?? '' }}</td>
                <td class="text-end">৳{{ number_format($abr['bucket_0_30'] ?? 0, 2) }}</td>
                <td class="text-end">৳{{ number_format($abr['bucket_31_60'] ?? 0, 2) }}</td>
                <td class="text-end">৳{{ number_format($abr['bucket_61_90'] ?? 0, 2) }}</td>
                <td class="text-end">৳{{ number_format($abr['bucket_90_plus'] ?? 0, 2) }}</td>
                <td class="text-end fw-bold">৳{{ number_format($abr['total_receivable'] ?? 0, 2) }}</td>
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
        <i class="fas fa-database me-1"></i>Powered by PostgreSQL CTE — Single query with sub-ledger bucketing + GL reconciliation
    </span>
</div>
@endsection
