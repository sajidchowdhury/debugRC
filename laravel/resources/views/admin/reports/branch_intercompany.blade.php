@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-arrows-left-right me-2 text-primary"></i> Branch Intercompany Ledger</h2>
            <p class="text-muted mb-0 small">Due-from / due-to balances between branches — settlement trail.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.branchIntercompany') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small mb-1">Branch (optional — leave empty for all)</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) old('branch_id', request('branch_id')) === $b->id)>{{ $b->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                </div>
                <div class="col-md-6 text-md-end">
                    <span class="badge {{ $checks['zero_sum'] ? 'bg-success' : 'bg-danger' }} fs-6">
                        <i class="fas {{ $checks['zero_sum'] ? 'fa-check-circle' : 'fa-exclamation-triangle' }} me-1"></i>
                        Zero-sum check: {{ $checks['zero_sum'] ? 'PASS' : 'FAIL' }}
                    </span>
                </div>
            </form>
        </div>
    </div>

    <div class="alert alert-info py-2 mb-3">
        <i class="fas fa-info-circle me-1"></i>
        <strong>Note:</strong> Intercompany balances must net to zero across all branches. Any non-zero variance indicates an unbalanced transfer.
    </div>

    {{-- Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>From Branch</th>
                            <th>To Branch</th>
                            <th class="text-end">Total Debit</th>
                            <th class="text-end">Total Credit</th>
                            <th class="text-end">Net Balance</th>
                            <th class="text-end">Outstanding</th>
                            <th class="text-center">Entries</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data as $r)
                            <tr>
                                <td><i class="fas fa-building me-1 text-muted"></i>{{ $r->from_branch_name }}</td>
                                <td><i class="fas fa-arrow-right me-1 text-muted"></i>{{ $r->to_branch_name }}</td>
                                <td class="text-end">{{ number_format($r->total_debit, 2) }}</td>
                                <td class="text-end text-success">{{ number_format($r->total_credit, 2) }}</td>
                                <td class="text-end">{{ number_format($r->net_balance, 2) }}</td>
                                <td class="text-end {{ $r->outstanding_amount > 0 ? 'text-danger fw-semibold' : 'text-muted' }}">{{ number_format($r->outstanding_amount, 2) }}</td>
                                <td class="text-center">{{ $r->entry_count }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-muted text-center py-3">No intercompany transactions.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="2" class="text-end">TOTAL</td>
                            <td class="text-end">{{ number_format($totals['total_debit'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['total_credit'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['net_balance'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['total_outstanding'], 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
