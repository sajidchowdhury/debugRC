@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-users me-2 text-primary"></i> Customer Performance</h2>
            <p class="text-muted mb-0 small">360° customer value — invoice count, revenue, paid, due — sorted by total revenue.</p>
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
            <form method="GET" action="{{ route('admin.reports.customerPerformance') }}" class="row g-2 align-items-end">
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

    {{-- Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="custPerfTable" class="table table-sm table-striped table-hover mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th width="40">#</th>
                            <th>Customer</th>
                            <th class="text-center">Invoices</th>
                            <th class="text-end">Total Revenue</th>
                            <th class="text-end">Total Paid</th>
                            <th class="text-end">Total Due</th>
                            <th>Last Invoice</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data as $idx => $r)
                            <tr class="{{ $idx < 10 ? 'table-warning' : '' }}">
                                <td>
                                    @if ($idx < 10)
                                        <span class="badge bg-warning text-dark"><i class="fas fa-trophy me-1"></i>{{ $idx + 1 }}</span>
                                    @else
                                        <span class="text-muted">{{ $idx + 1 }}</span>
                                    @endif
                                </td>
                                <td>
                                    <code>{{ $r->customer_code }}</code><br>
                                    <span class="fw-semibold">{{ $r->customer_name }}</span>
                                </td>
                                <td class="text-center">{{ number_format($r->invoice_count) }}</td>
                                <td class="text-end fw-semibold">{{ number_format($r->total_revenue, 2) }}</td>
                                <td class="text-end text-success">{{ number_format($r->total_paid, 2) }}</td>
                                <td class="text-end {{ $r->total_due > 0 ? 'text-danger fw-semibold' : '' }}">{{ number_format($r->total_due, 2) }}</td>
                                <td>{{ $r->last_invoice_date ? \Carbon\Carbon::parse($r->last_invoice_date)->format('d M Y') : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-muted text-center py-3">No customer activity in the period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-2">
        <i class="fas fa-trophy me-1 text-warning"></i>
        Top 10 customers (by total revenue) are highlighted in yellow.
    </p>
</div>
@endsection

@push('scripts')
<script>
$(function() {
    $('#custPerfTable').DataTable({
        paging: false,
        searching: true,
        ordering: true,
        info: false,
        order: [[3, 'desc']]
    });
});
</script>
@endpush
