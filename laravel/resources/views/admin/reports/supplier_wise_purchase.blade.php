@extends('layouts.admin')

@php
    $totalReceive = $data->sum('receive_count');
    $totalPurchase = $data->sum('total_purchase');
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-industry me-2 text-primary"></i> Supplier-wise Purchase</h2>
            <p class="text-muted mb-0 small">Spend profile per supplier — receive count &amp; total purchase value.</p>
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
            <form method="GET" action="{{ route('admin.reports.supplierWisePurchase') }}" class="row g-2 align-items-end">
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
                <table id="suppPurchTable" class="table table-sm table-striped table-hover mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Supplier</th>
                            <th class="text-center">Receive Count</th>
                            <th class="text-end">Total Purchase</th>
                            <th>Last Receive Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data as $r)
                            <tr>
                                <td>
                                    <code>{{ $r->supplier_code }}</code><br>
                                    <span class="fw-semibold">{{ $r->supplier_name }}</span>
                                </td>
                                <td class="text-center">{{ number_format($r->receive_count) }}</td>
                                <td class="text-end fw-semibold">{{ number_format($r->total_purchase, 2) }}</td>
                                <td>{{ $r->last_receive_date ? \Carbon\Carbon::parse($r->last_receive_date)->format('d M Y') : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted text-center py-3">No purchases in the selected period.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td class="text-end">TOTAL</td>
                            <td class="text-center">{{ number_format($totalReceive) }}</td>
                            <td class="text-end">{{ number_format($totalPurchase, 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function() {
    $('#suppPurchTable').DataTable({
        paging: false,
        searching: true,
        ordering: true,
        info: false,
        order: [[2, 'desc']]
    });
});
</script>
@endpush
