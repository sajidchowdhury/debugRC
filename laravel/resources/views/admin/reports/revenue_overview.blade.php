@extends('layouts.admin')

@php
    $statusBadge = [
        'draft'      => 'secondary',
        'finalized'  => 'primary',
        'paid'       => 'success',
        'partial'    => 'warning',
        'overdue'    => 'danger',
        'cancelled'  => 'dark',
    ];
    $totalInvoices = method_exists($data, 'total') ? $data->total() : $data->count();
    $rows          = method_exists($data, 'getCollection') ? $data->getCollection() : $data;
    $totalRevenue  = $rows->sum('total_amount');
    $totalPaid     = $rows->sum('paid_amount');
    $totalDue      = $rows->sum('due_amount');
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i> Revenue Overview</h2>
            <p class="text-muted mb-0 small">Invoice-level register with customer &amp; salesman filters — {{ \Carbon\Carbon::parse($meta['from_date'])->format('d M Y') }} → {{ \Carbon\Carbon::parse($meta['to_date'])->format('d M Y') }}.</p>
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
            <form method="GET" action="{{ route('admin.reports.revenueOverview') }}" class="row g-2 align-items-end">
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
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:38px;height:38px;background:#2563eb;"><i class="fas fa-file"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ number_format($totalInvoices) }}</div>
                        <small class="text-muted">Total invoices</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:38px;height:38px;background:#16a34a;"><i class="fas fa-money-bill-trend-up"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1 text-success">Tk {{ number_format($totalRevenue, 2) }}</div>
                        <small class="text-muted">Total revenue</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:38px;height:38px;background:#0ea5e9;"><i class="fas fa-check"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">Tk {{ number_format($totalPaid, 2) }}</div>
                        <small class="text-muted">Total paid</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:38px;height:38px;background:#dc2626;"><i class="fas fa-hourglass-half"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1 text-danger">Tk {{ number_format($totalDue, 2) }}</div>
                        <small class="text-muted">Total due</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="revTable" class="table table-sm table-striped table-hover mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Branch</th>
                            <th>Salesman</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data as $r)
                            <tr>
                                <td><code>{{ $r->invoice_code }}</code></td>
                                <td>{{ \Carbon\Carbon::parse($r->invoice_date)->format('d M Y') }}</td>
                                <td>{{ $r->customer_name ?? '—' }}</td>
                                <td>{{ $r->branch_name ?? '—' }}</td>
                                <td>{{ $r->salesman_name ?? '—' }}</td>
                                <td class="text-center"><span class="badge bg-{{ $statusBadge[$r->status] ?? 'secondary' }}-subtle text-{{ $statusBadge[$r->status] ?? 'secondary' }}">{{ ucfirst($r->status) }}</span></td>
                                <td class="text-end">{{ number_format($r->total_amount, 2) }}</td>
                                <td class="text-end text-success">{{ number_format($r->paid_amount, 2) }}</td>
                                <td class="text-end {{ $r->due_amount > 0 ? 'text-danger fw-semibold' : '' }}">{{ number_format($r->due_amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-muted text-center py-3">No invoices in the selected period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($data->hasPages())
            <div class="card-footer bg-white py-2">{{ $data->links() }}</div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function() {
    $('#revTable').DataTable({
        paging: false,
        searching: true,
        ordering: true,
        info: false,
        order: [[1, 'desc']]
    });
});
</script>
@endpush
