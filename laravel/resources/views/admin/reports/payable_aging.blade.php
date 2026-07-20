@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-clock me-2 text-primary"></i> Payable Aging</h2>
            <p class="text-muted mb-0 small">Supplier due balances by age bucket, as of {{ \Carbon\Carbon::parse($meta['as_of_date'])->format('d M Y') }}.</p>
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
            <form method="GET" action="{{ route('admin.reports.payableAging') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">As of date</label>
                    <input type="date" name="as_of_date" class="form-control form-control-sm"
                           value="{{ old('as_of_date', request('as_of_date', $meta['as_of_date'] ?? '')) }}">
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) old('branch_id', request('branch_id', $meta['branch_id'] ?? '')) === $b->id)>{{ $b->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                </div>
                <div class="col-md-3 text-end">
                    <span class="badge {{ ($meta['source'] ?? '') === 'materialized_view' ? 'bg-info' : 'bg-secondary' }} text-dark fs-6">
                        <i class="fas {{ ($meta['source'] ?? '') === 'materialized_view' ? 'fa-bolt' : 'fa-database' }} me-1"></i>
                        Source: {{ ($meta['source'] ?? '') === 'materialized_view' ? 'Materialized View' : 'Direct Query (historical as-of)' }}
                    </span>
                </div>
            </form>
        </div>
    </div>

    {{-- Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="apAgingTable" class="table table-sm table-striped table-hover mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Supplier</th>
                            <th>Mobile</th>
                            <th>Branch</th>
                            <th class="text-end">0–30</th>
                            <th class="text-end">31–60</th>
                            <th class="text-end">61–90</th>
                            <th class="text-end">90+</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data as $r)
                            <tr>
                                <td>
                                    <code>{{ $r->supplier_code }}</code><br>
                                    <span class="fw-semibold">{{ $r->supplier_name }}</span>
                                </td>
                                <td>{{ $r->mobile ?? '—' }}</td>
                                <td>{{ $r->branch_name ?? '—' }}</td>
                                <td class="text-end">{{ number_format($r->bucket_0_30, 2) }}</td>
                                <td class="text-end">{{ number_format($r->bucket_31_60, 2) }}</td>
                                <td class="text-end">{{ number_format($r->bucket_61_90, 2) }}</td>
                                <td class="text-end {{ $r->bucket_90_plus > 0 ? 'text-danger fw-semibold' : '' }}">{{ number_format($r->bucket_90_plus, 2) }}</td>
                                <td class="text-end fw-bold">{{ number_format($r->total_payable, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-muted text-center py-3">No outstanding payables.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="3" class="text-end">TOTAL</td>
                            <td class="text-end">{{ number_format($totals['bucket_0_30'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['bucket_31_60'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['bucket_61_90'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['bucket_90_plus'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['total_payable'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- GL reconciliation footnote --}}
    <div class="alert {{ $checks['matches_gl'] ? 'alert-success' : 'alert-danger' }} mt-3 mb-0">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <i class="fas {{ $checks['matches_gl'] ? 'fa-check-circle' : 'fa-exclamation-triangle' }} me-1"></i>
                <strong>GL Reconciliation:</strong>
                AP Control (GL) = <strong>Tk {{ number_format($totals['gl_ap_control'], 2) }}</strong> |
                Sub-ledger total = <strong>Tk {{ number_format($totals['total_payable'], 2) }}</strong> |
                Variance = <strong>Tk {{ number_format(abs($totals['total_payable'] - $totals['gl_ap_control']), 2) }}</strong>
            </div>
            <span class="badge {{ $checks['matches_gl'] ? 'bg-success' : 'bg-danger' }}">
                {{ $checks['matches_gl'] ? 'MATCHES' : 'OUT OF BALANCE' }}
            </span>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function() {
    $('#apAgingTable').DataTable({
        paging: false,
        searching: true,
        ordering: true,
        info: false,
        order: [[7, 'desc']]
    });
});
</script>
@endpush
