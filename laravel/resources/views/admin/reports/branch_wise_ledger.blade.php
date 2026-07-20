@extends('layouts.admin')

@php
    $accountTypeBadge = [
        'Asset'     => 'primary',
        'Liability' => 'warning',
        'Equity'    => 'info',
        'Income'    => 'success',
        'Expense'   => 'danger',
    ];
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-sitemap me-2 text-primary"></i> Branch-wise Ledger</h2>
            <p class="text-muted mb-0 small">Per-branch GL activity summary — {{ \Carbon\Carbon::parse($meta['from_date'])->format('d M Y') }} → {{ \Carbon\Carbon::parse($meta['to_date'])->format('d M Y') }}.</p>
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
            <form method="GET" action="{{ route('admin.reports.branchWiseLedger') }}" class="row g-2 align-items-end">
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

    {{-- Branch cards --}}
    @forelse ($branches as $branchName => $rows)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                <span><i class="fas fa-building me-1 text-primary"></i><strong>{{ $branchName }}</strong></span>
                <span class="badge bg-secondary">{{ $rows->count() }} account types</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Account Type</th>
                                <th>Nature</th>
                                <th class="text-end">Total Debit</th>
                                <th class="text-end">Total Credit</th>
                                <th class="text-end">Net</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $branchDr = 0; $branchCr = 0; @endphp
                            @foreach ($rows as $r)
                                @php
                                    $branchDr += $r->total_debit;
                                    $branchCr += $r->total_credit;
                                @endphp
                                <tr>
                                    <td>
                                        <span class="badge bg-{{ $accountTypeBadge[$r->account_type] ?? 'secondary' }}-subtle text-{{ $accountTypeBadge[$r->account_type] ?? 'secondary' }}">{{ $r->account_type }}</span>
                                    </td>
                                    <td><small class="text-muted">{{ $r->ledger_nature }}</small></td>
                                    <td class="text-end">{{ number_format($r->total_debit, 2) }}</td>
                                    <td class="text-end">{{ number_format($r->total_credit, 2) }}</td>
                                    <td class="text-end fw-semibold {{ ($r->total_debit - $r->total_credit) >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ number_format($r->total_debit - $r->total_credit, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="2" class="text-end">Branch Total</td>
                                <td class="text-end">{{ number_format($branchDr, 2) }}</td>
                                <td class="text-end">{{ number_format($branchCr, 2) }}</td>
                                <td class="text-end">{{ number_format($branchDr - $branchCr, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    @empty
        <div class="alert alert-info">No GL activity found in the selected period.</div>
    @endforelse

    {{-- Overall totals --}}
    <div class="card border-0 shadow-sm bg-dark text-white">
        <div class="card-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="fas fa-globe me-1"></i> Overall Totals (all branches)</span>
            <div class="d-flex gap-4">
                <span>Total Debit: <strong>Tk {{ number_format($totals['total_debit'], 2) }}</strong></span>
                <span>Total Credit: <strong>Tk {{ number_format($totals['total_credit'], 2) }}</strong></span>
            </div>
        </div>
    </div>
</div>
@endsection
