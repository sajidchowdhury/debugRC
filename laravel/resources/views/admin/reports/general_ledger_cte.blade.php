@extends('layouts.admin')

@section('content')
<div class="row mb-3">
    <div class="col-12">
        <h4 class="mb-0">
            <i class="fas fa-book text-indigo me-2"></i>
            {{ $meta['title'] ?? 'General Ledger (CTE)' }}
        </h4>
        <small class="text-muted">
            {{ $meta['from_date'] ?? '' }} to {{ $meta['to_date'] ?? '' }}
            &middot; Source: <span class="badge bg-info">{{ $meta['source'] ?? 'cte' }}</span>
            &middot; Balance: <span class="badge {{ ($checks['balanced'] ?? false) ? 'bg-success' : 'bg-danger' }}">{{ ($checks['balanced'] ?? false) ? 'Dr = Cr' : 'OUT OF BALANCE' }}</span>
        </small>
    </div>
</div>

{{-- Filter --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('admin.reports.generalLedgerCte') }}" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="from_date" class="form-control form-control-sm" value="{{ $meta['from_date'] ?? '' }}">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="to_date" class="form-control form-control-sm" value="{{ $meta['to_date'] ?? '' }}">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">Ledger</label>
                <select name="ledger_id" class="form-select form-select-sm" style="min-width:200px">
                    <option value="">All Ledgers</option>
                    @foreach($ledgers ?? [] as $l)
                    <option value="{{ $l->id }}" {{ ($meta['ledger_id'] ?? '') == $l->id ? 'selected' : '' }}>{{ $l->ledger_code }} — {{ $l->ledger_name }}</option>
                    @endforeach
                </select>
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
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync-alt me-1"></i>Run</button>
            </div>
        </form>
    </div>
</div>

{{-- Ledger Summary --}}
@if(($ledger_summary ?? collect())->count() > 0)
<div class="card mb-3">
    <div class="card-header py-2"><i class="fas fa-list me-1"></i>Ledger Summary</div>
    <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light sticky-top">
                <tr>
                    <th>Code</th><th>Ledger</th><th class="text-end">Opening</th>
                    <th class="text-end">Period Dr</th><th class="text-end">Period Cr</th>
                    <th class="text-end fw-bold">Closing</th>
                </tr>
            </thead>
            <tbody>
            @foreach($ledger_summary as $ls)
            <tr>
                <td>{{ $ls['ledger_code'] ?? '' }}</td>
                <td>{{ $ls['ledger_name'] ?? '' }}</td>
                <td class="text-end">৳{{ number_format($ls['opening_balance'] ?? 0, 2) }}</td>
                <td class="text-end">৳{{ number_format($ls['period_debit'] ?? 0, 2) }}</td>
                <td class="text-end">৳{{ number_format($ls['period_credit'] ?? 0, 2) }}</td>
                <td class="text-end fw-bold">৳{{ number_format($ls['closing_balance'] ?? 0, 2) }}</td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- Journal Lines with Running Balance --}}
<div class="card mb-4">
    <div class="card-header py-2 d-flex justify-content-between">
        <span><i class="fas fa-receipt me-1"></i>Journal Entries with Running Balance</span>
        <span class="badge bg-secondary">{{ ($data ?? collect())->count() }} entries</span>
    </div>
    <div class="card-body p-0" style="max-height:500px;overflow-y:auto">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light sticky-top">
                <tr>
                    <th>Date</th><th>Entry #</th><th>Ledger</th><th>Description</th>
                    <th>Ref</th><th class="text-end">Debit</th><th class="text-end">Credit</th>
                    <th class="text-end fw-bold">Running Balance</th>
                </tr>
            </thead>
            <tbody>
            @foreach(($data ?? collect()) as $row)
            <tr>
                <td>{{ $row['entry_date'] ?? '' }}</td>
                <td>{{ $row['entry_no'] ?? '' }}</td>
                <td><small>{{ $row['ledger_code'] ?? '' }} — {{ $row['ledger_name'] ?? '' }}</small></td>
                <td><small>{{ $row['description'] ?? '' }}</small></td>
                <td><small>{{ $row['reference_type'] ?? '' }} #{{ $row['reference_id'] ?? '' }}</small></td>
                <td class="text-end">{{ ($row['debit'] ?? 0) > 0 ? '৳' . number_format($row['debit'], 2) : '' }}</td>
                <td class="text-end">{{ ($row['credit'] ?? 0) > 0 ? '৳' . number_format($row['credit'], 2) : '' }}</td>
                <td class="text-end fw-bold {{ ($row['running_balance'] ?? 0) < 0 ? 'text-danger' : '' }}">৳{{ number_format($row['running_balance'] ?? 0, 2) }}</td>
            </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light">
                <tr class="fw-bold">
                    <td colspan="5">TOTALS</td>
                    <td class="text-end">৳{{ number_format($totals['total_debit'] ?? 0, 2) }}</td>
                    <td class="text-end">৳{{ number_format($totals['total_credit'] ?? 0, 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="text-center mb-3">
    <span class="badge bg-info">
        <i class="fas fa-database me-1"></i>Powered by PostgreSQL CTE + Window Function — Running balance computed in SQL, not PHP
    </span>
</div>
@endsection
