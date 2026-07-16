@extends('layouts.admin')

@php
    $refTypeBadge = [
        'sales_invoice'        => 'primary',
        'sales_challan'        => 'primary',
        'sales_return'         => 'danger',
        'purchase_receive'     => 'success',
        'purchase_return'      => 'danger',
        'customer_payment'     => 'info',
        'supplier_payment'     => 'info',
        'stock_adjustment'     => 'warning',
        'warehouse_transfer'   => 'secondary',
        'damage'               => 'danger',
        'manual_journal'       => 'dark',
        'other_income'         => 'success',
        'other_expense'        => 'danger',
        'money_transfer'       => 'info',
        'employee_transaction' => 'warning',
    ];
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-file-invoice me-2 text-primary"></i> Journal Entries</h2>
            <p class="text-muted mb-0 small">Search all journal entries — filter by type, branch, date.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
            <span class="badge bg-info text-dark fs-6">
                <i class="fas fa-bolt me-1"></i> Source: Materialized View
            </span>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.journalEntries') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">From date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="{{ old('from_date', request('from_date', $meta['from_date'] ?? '')) }}">
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">To date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="{{ old('to_date', request('to_date', $meta['to_date'] ?? '')) }}">
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
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">Reference type</label>
                    <select name="reference_type" class="form-select form-select-sm">
                        <option value="">All types</option>
                        @foreach ($referenceTypes as $val => $lbl)
                            <option value="{{ $val }}" @selected(old('reference_type', request('reference_type', $meta['reference_type'] ?? '')) === $val)>{{ $lbl }}</option>
                        @endforeach
                    </select>
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
                <table id="jeTable" class="table table-sm table-striped table-hover mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Entry No</th>
                            <th>Type</th>
                            <th>Branch</th>
                            <th>Description</th>
                            <th class="text-end">Debit</th>
                            <th class="text-end">Credit</th>
                            <th class="text-center">Lines</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data as $r)
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($r->entry_date)->format('d M Y') }}</td>
                                <td><code>{{ $r->entry_no }}</code></td>
                                <td><span class="badge bg-{{ $refTypeBadge[$r->reference_type] ?? 'secondary' }}-subtle text-{{ $refTypeBadge[$r->reference_type] ?? 'secondary' }}">{{ str_replace('_', ' ', $r->reference_type) }}</span></td>
                                <td>{{ $r->branch_name ?? '—' }}</td>
                                <td>{{ $r->description ?? '—' }}</td>
                                <td class="text-end">{{ number_format($r->total_debit, 2) }}</td>
                                <td class="text-end">{{ number_format($r->total_credit, 2) }}</td>
                                <td class="text-center">{{ $r->line_count ?? '—' }}</td>
                                <td class="text-center">
                                    @if (!empty($r->is_reversed))
                                        <span class="badge bg-danger">Reversed</span>
                                    @else
                                        <span class="badge bg-success">Posted</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-muted text-center py-3">No journal entries found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($data->hasPages())
            <div class="card-footer bg-white py-2">
                {{ $data->links() }}
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function() {
    $('#jeTable').DataTable({
        paging: false,
        searching: true,
        ordering: true,
        info: false,
        pageLength: 50,
        order: [[0, 'desc']],
        columnDefs: [
            { orderable: false, targets: [2, 7, 8] }
        ]
    });
});
</script>
@endpush
