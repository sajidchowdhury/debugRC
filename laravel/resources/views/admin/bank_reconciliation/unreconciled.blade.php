@extends('layouts.admin')
@section('title', $title)

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i> Unreconciled Bank Entries</h4>
        <a href="{{ route('admin.bank-reconciliation.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>

    {{-- Summary --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row text-center">
                <div class="col-md-4">
                    <h5 class="mb-0">{{ $entries->count() }}</h5>
                    <small class="text-muted">Total Unreconciled</small>
                </div>
                <div class="col-md-4">
                    <h5 class="mb-0">{{ number_format($entries->sum('debit') - $entries->sum('credit'), 2) }}</h5>
                    <small class="text-muted">Net Amount</small>
                </div>
                <div class="col-md-4">
                    <h5 class="mb-0">{{ $grouped->count() }}</h5>
                    <small class="text-muted">Bank Accounts</small>
                </div>
            </div>
        </div>
    </div>

    {{-- By Bank --}}
    @foreach($grouped as $bankName => $bankEntries)
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light py-2">
            <h6 class="mb-0">
                <i class="fas fa-university me-1"></i> {{ $bankName }}
                <span class="badge bg-secondary ms-2">{{ $bankEntries->count() }} entries</span>
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Entry #</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Source</th>
                            <th>Branch</th>
                            <th class="text-end">Debit</th>
                            <th class="text-end">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bankEntries as $entry)
                        <tr>
                            <td>{{ $entry->entry_no }}</td>
                            <td>{{ \Carbon\Carbon::parse($entry->entry_date)->format('d M Y') }}</td>
                            <td>{{ $entry->entry_description }}</td>
                            <td><span class="badge bg-secondary">{{ $entry->entry_source }}</span></td>
                            <td>{{ $entry->branch_name }}</td>
                            <td class="text-end">{{ $entry->debit > 0 ? number_format($entry->debit, 2) : '' }}</td>
                            <td class="text-end">{{ $entry->credit > 0 ? number_format($entry->credit, 2) : '' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="5" class="text-end fw-bold">Total</td>
                            <td class="text-end fw-bold">{{ number_format($bankEntries->sum('debit'), 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($bankEntries->sum('credit'), 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    @endforeach

    @if($entries->isEmpty())
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5 text-muted">
            <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
            <h5>All bank entries are reconciled!</h5>
            <p>There are no unreconciled bank entries at this time.</p>
        </div>
    </div>
    @endif
</div>
@endsection
