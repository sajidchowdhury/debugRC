@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-book me-2 text-primary"></i> Day Book (Cash &amp; Bank)</h2>
            <p class="text-muted mb-0 small">Split view: receipts vs payments in the period {{ \Carbon\Carbon::parse($meta['from_date'])->format('d M Y') }} → {{ \Carbon\Carbon::parse($meta['to_date'])->format('d M Y') }}.</p>
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
            <form method="GET" action="{{ route('admin.reports.dailyCashBook') }}" class="row g-2 align-items-end">
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
            </form>
        </div>
    </div>

    {{-- Two-column receipts / payments --}}
    <div class="row g-3">
        {{-- Receipts (credit side) --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-success text-white py-2 d-flex justify-content-between">
                    <span><i class="fas fa-arrow-down me-1"></i> Receipts (Credit)</span>
                    <span class="badge bg-light text-success">{{ $receipts->count() }} lines</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="90">Date</th>
                                    <th width="110">Entry No</th>
                                    <th>Description</th>
                                    <th>Ledger</th>
                                    <th class="text-end" width="120">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($receipts as $r)
                                    <tr>
                                        <td>{{ \Carbon\Carbon::parse($r->entry_date)->format('d M') }}</td>
                                        <td><code>{{ $r->entry_no }}</code></td>
                                        <td>{{ $r->description }}</td>
                                        <td><small class="text-muted">{{ $r->ledger_name }}</small></td>
                                        <td class="text-end text-success fw-semibold">{{ number_format($r->credit, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-muted text-center py-3">No receipts in the period.</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot class="table-success fw-bold">
                                <tr>
                                    <td colspan="4" class="text-end">Total Receipts</td>
                                    <td class="text-end">{{ number_format($totals['total_receipts'], 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Payments (debit side) --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-danger text-white py-2 d-flex justify-content-between">
                    <span><i class="fas fa-arrow-up me-1"></i> Payments (Debit)</span>
                    <span class="badge bg-light text-danger">{{ $payments->count() }} lines</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="90">Date</th>
                                    <th width="110">Entry No</th>
                                    <th>Description</th>
                                    <th>Ledger</th>
                                    <th class="text-end" width="120">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($payments as $r)
                                    <tr>
                                        <td>{{ \Carbon\Carbon::parse($r->entry_date)->format('d M') }}</td>
                                        <td><code>{{ $r->entry_no }}</code></td>
                                        <td>{{ $r->description }}</td>
                                        <td><small class="text-muted">{{ $r->ledger_name }}</small></td>
                                        <td class="text-end text-danger fw-semibold">{{ number_format($r->debit, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-muted text-center py-3">No payments in the period.</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot class="table-danger fw-bold">
                                <tr>
                                    <td colspan="4" class="text-end">Total Payments</td>
                                    <td class="text-end">{{ number_format($totals['total_payments'], 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Net --}}
    <div class="card border-0 shadow-sm mt-3 {{ $totals['net'] >= 0 ? 'bg-success-subtle' : 'bg-danger-subtle' }}">
        <div class="card-body py-3 d-flex justify-content-between align-items-center">
            <span class="fw-semibold">
                <i class="fas {{ $totals['net'] >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down' }} me-1"></i>
                Net Cash Movement (Receipts − Payments)
            </span>
            <span class="fw-bold fs-4 {{ $totals['net'] >= 0 ? 'text-success' : 'text-danger' }}">
                Tk {{ number_format($totals['net'], 2) }}
            </span>
        </div>
    </div>
</div>
@endsection
