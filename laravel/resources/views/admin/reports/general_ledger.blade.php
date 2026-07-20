@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-book-open me-2 text-primary"></i> General Ledger</h2>
            <p class="text-muted mb-0 small">Account activity with running balance — {{ \Carbon\Carbon::parse($meta['from_date'])->format('d M Y') }} → {{ \Carbon\Carbon::parse($meta['to_date'])->format('d M Y') }}.</p>
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
            <form method="GET" action="{{ route('admin.reports.generalLedger') }}" class="row g-2 align-items-end">
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
                <div class="col-sm-12 col-md-4">
                    <label class="form-label small mb-1">Ledger</label>
                    <select name="ledger_id" class="form-select form-select-sm select2-ledger">
                        <option value="">All ledgers</option>
                        @foreach ($ledgers as $l)
                            <option value="{{ $l->id }}" @selected((int) old('ledger_id', request('ledger_id', $meta['ledger_id'] ?? '')) === $l->id)>
                                {{ $l->ledger_code }} — {{ $l->ledger_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-12 col-md-3">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm select2-branch">
                        <option value="">All branches</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) old('branch_id', request('branch_id', $meta['branch_id'] ?? '')) === $b->id)>{{ $b->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Balance check --}}
    <div class="alert {{ $checks['balanced'] ? 'alert-success' : 'alert-danger' }} py-2 mb-3">
        <i class="fas {{ $checks['balanced'] ? 'fa-check-circle' : 'fa-exclamation-triangle' }} me-1"></i>
        Total debit vs credit:
        <strong>Tk {{ number_format($totals['total_debit'], 2) }}</strong> vs
        <strong>Tk {{ number_format($totals['total_credit'], 2) }}</strong>
        — variance <strong>Tk {{ number_format(abs($totals['total_debit'] - $totals['total_credit']), 2) }}</strong>.
    </div>

    {{-- Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="100">Date</th>
                            <th width="120">Entry No</th>
                            <th width="100">Code</th>
                            <th>Ledger</th>
                            <th>Description</th>
                            <th class="text-end" width="120">Debit</th>
                            <th class="text-end" width="120">Credit</th>
                            <th class="text-end" width="140">Running Bal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $currentLedger = null;
                            $ledgerSubDr = 0;
                            $ledgerSubCr = 0;
                        @endphp
                        @foreach ($data as $r)
                            @if ($currentLedger !== $r->ledger_id)
                                @if ($currentLedger !== null)
                                    <tr class="table-light fw-semibold">
                                        <td colspan="5" class="text-end">Subtotal</td>
                                        <td class="text-end">{{ number_format($ledgerSubDr, 2) }}</td>
                                        <td class="text-end">{{ number_format($ledgerSubCr, 2) }}</td>
                                        <td></td>
                                    </tr>
                                @endif
                                @php
                                    $currentLedger = $r->ledger_id;
                                    $ledgerSubDr = 0;
                                    $ledgerSubCr = 0;
                                @endphp
                                <tr class="table-primary">
                                    <td colspan="8">
                                        <i class="fas fa-bookmark me-1"></i>
                                        <strong>{{ $r->ledger_code }} — {{ $r->ledger_name }}</strong>
                                        @if (!empty($r->branch_name)) <span class="badge bg-light text-dark ms-1">{{ $r->branch_name }}</span> @endif
                                    </td>
                                </tr>
                            @endif
                            @php
                                $ledgerSubDr += $r->debit;
                                $ledgerSubCr += $r->credit;
                            @endphp
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($r->entry_date)->format('d M Y') }}</td>
                                <td><code>{{ $r->entry_no }}</code></td>
                                <td><code>{{ $r->ledger_code }}</code></td>
                                <td>{{ $r->ledger_name }}</td>
                                <td>{{ $r->description }}
                                    @if (!empty($r->reference_type))
                                        <br><small class="text-muted"><i class="fas fa-link me-1"></i>{{ $r->reference_type }} #{{ $r->reference_id }}</small>
                                    @endif
                                </td>
                                <td class="text-end">{{ $r->debit ? number_format($r->debit, 2) : '' }}</td>
                                <td class="text-end">{{ $r->credit ? number_format($r->credit, 2) : '' }}</td>
                                <td class="text-end fw-semibold {{ $r->running_balance >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($r->running_balance, 2) }}</td>
                            </tr>
                        @endforeach
                        @if ($currentLedger !== null)
                            <tr class="table-light fw-semibold">
                                <td colspan="5" class="text-end">Subtotal</td>
                                <td class="text-end">{{ number_format($ledgerSubDr, 2) }}</td>
                                <td class="text-end">{{ number_format($ledgerSubCr, 2) }}</td>
                                <td></td>
                            </tr>
                        @endif
                        @if ($data->isEmpty())
                            <tr><td colspan="8" class="text-muted text-center py-3">No journal lines found for the selected filters.</td></tr>
                        @endif
                    </tbody>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="5" class="text-end">GRAND TOTAL</td>
                            <td class="text-end">{{ number_format($totals['total_debit'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['total_credit'], 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    @if ($data->count() > 500)
        <p class="text-muted small mt-2">
            <i class="fas fa-info-circle me-1"></i>
            Showing {{ $data->count() }} journal lines. For larger datasets, narrow the date range or filter by a specific ledger.
        </p>
    @endif
</div>
@endsection

@push('scripts')
<script>
$(function() {
    $('.select2-ledger, .select2-branch').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: '— select —',
        allowClear: true,
    });
});
</script>
@endpush
