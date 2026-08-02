@extends('layouts.admin')

@section('title', 'Intercompany Reconciliation — Remote Center ERP')

@section('content')
<div class="container-fluid py-2">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('admin.consolidation.index') }}">Consolidation</a></li>
                    <li class="breadcrumb-item active">Intercompany Reconciliation</li>
                </ol>
            </nav>
            <h2 class="h4 mb-1"><i class="fas fa-exchange-alt me-2 text-primary"></i> Intercompany Reconciliation</h2>
            <p class="text-muted mb-0 small">
                As of {{ \Carbon\Carbon::parse($meta['as_of_date'])->format('d-m-Y') }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-print me-1"></i> Print
            </button>
            <a href="{{ route('admin.consolidation.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.consolidation.reconciliation') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small mb-1">As of date</label>
                    <input type="date" name="as_of_date" class="form-control form-control-sm"
                           value="{{ old('as_of_date', request('as_of_date', $meta['as_of_date'] ?? '')) }}">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Run Reconciliation
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Balance check indicator --}}
    <div class="d-flex gap-2 mb-3">
        @php
            $isBalanced = ($totals['due_from'] ?? 0) == ($totals['due_to'] ?? 0);
            $imbalance = abs(($totals['due_from'] ?? 0) - ($totals['due_to'] ?? 0));
        @endphp
        <span class="badge {{ $isBalanced ? 'bg-success' : 'bg-danger' }} py-2 px-3">
            <i class="fas fa-{{ $isBalanced ? 'check-circle' : 'exclamation-triangle' }} me-1"></i>
            {{ $isBalanced ? 'Intercompany Balanced: Due From = Due To' : 'OUT OF BALANCE — Imbalance: ' . number_format($imbalance, 2) }}
        </span>
    </div>

    {{-- Summary cards --}}
    <div class="row g-2 mb-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block"><i class="fas fa-arrow-right me-1"></i> Due From (Receivable)</small>
                    <div class="fs-5 fw-bold text-primary">{{ number_format($totals['due_from'] ?? 0, 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block"><i class="fas fa-arrow-left me-1"></i> Due To (Payable)</small>
                    <div class="fs-5 fw-bold text-warning">{{ number_format($totals['due_to'] ?? 0, 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block"><i class="fas fa-balance-scale me-1"></i> Imbalance</small>
                    <div class="fs-5 fw-bold {{ $isBalanced ? 'text-success' : 'text-danger' }}">{{ number_format($imbalance, 2) }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Branch Pairs Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-code-branch me-1"></i> Intercompany Branch Pairs</h5>
            <span class="badge bg-secondary">{{ $data->count() }} pairs</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size: 0.85rem;">
                    <thead>
                        <tr class="table-dark">
                            <th style="min-width: 160px;">From Branch</th>
                            <th style="min-width: 160px;">To Branch</th>
                            <th class="text-end" style="min-width: 130px;">Total Debit</th>
                            <th class="text-end" style="min-width: 130px;">Total Credit</th>
                            <th class="text-end" style="min-width: 130px;">Net Balance</th>
                            <th class="text-center" style="min-width: 100px;">Active Entries</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($data as $row)
                        @php
                            $netBalance = ($row->total_debit ?? 0) - ($row->total_credit ?? 0);
                            $isPairBalanced = abs($netBalance) < 0.01;
                        @endphp
                        <tr class="{{ $isPairBalanced ? '' : 'table-warning' }}">
                            <td>
                                <span class="fw-semibold">{{ $row->from_branch_name ?? $row->fromBranch?->branch_name ?? '—' }}</span>
                                @if($row->from_branch_code ?? $row->fromBranch?->branch_code ?? null)
                                <small class="text-muted ms-1">[{{ $row->from_branch_code ?? $row->fromBranch?->branch_code }}]</small>
                                @endif
                            </td>
                            <td>
                                <span class="fw-semibold">{{ $row->to_branch_name ?? $row->toBranch?->branch_name ?? '—' }}</span>
                                @if($row->to_branch_code ?? $row->toBranch?->branch_code ?? null)
                                <small class="text-muted ms-1">[{{ $row->to_branch_code ?? $row->toBranch?->branch_code }}]</small>
                                @endif
                            </td>
                            <td class="text-end">
                                @if(($row->total_debit ?? 0) > 0.005)
                                    {{ number_format($row->total_debit, 2) }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if(($row->total_credit ?? 0) > 0.005)
                                    {{ number_format($row->total_credit, 2) }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold {{ $isPairBalanced ? 'text-success' : 'text-danger' }}">
                                @if(abs($netBalance) > 0.005)
                                    {{ number_format(abs($netBalance), 2) }}
                                    <small class="fw-bold {{ $netBalance > 0 ? 'text-success' : 'text-danger' }}">{{ $netBalance > 0 ? 'Dr' : 'Cr' }}</small>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary">{{ $row->active_entries ?? $row->entry_count ?? 0 }}</span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                No intercompany branch pairs found for the selected date.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                    @if($data->count() > 0)
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="2" class="text-end">Totals</td>
                            <td class="text-end">{{ number_format($totals['total_debit'] ?? 0, 2) }}</td>
                            <td class="text-end">{{ number_format($totals['total_credit'] ?? 0, 2) }}</td>
                            <td class="text-end">
                                @php
                                    $totalNet = ($totals['total_debit'] ?? 0) - ($totals['total_credit'] ?? 0);
                                @endphp
                                {{ number_format(abs($totalNet), 2) }}
                                <small>{{ $totalNet >= 0 ? 'Dr' : 'Cr' }}</small>
                            </td>
                            <td class="text-center">{{ $totals['total_entries'] ?? 0 }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>

    {{-- Totals reconciliation section --}}
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-light py-2">
            <h6 class="mb-0"><i class="fas fa-balance-scale me-1"></i> Reconciliation Summary</h6>
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-4">
                    <div class="text-muted small mb-1">Due From (IC Receivable)</div>
                    <div class="fw-bold fs-5 text-primary">{{ number_format($totals['due_from'] ?? 0, 2) }}</div>
                    <small class="text-muted">Sum of intercompany debit balances</small>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small mb-1">Due To (IC Payable)</div>
                    <div class="fw-bold fs-5 text-warning">{{ number_format($totals['due_to'] ?? 0, 2) }}</div>
                    <small class="text-muted">Sum of intercompany credit balances</small>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small mb-1">Imbalance</div>
                    <div class="fw-bold fs-5 {{ $isBalanced ? 'text-success' : 'text-danger' }}">
                        {{ number_format($imbalance, 2) }}
                    </div>
                    <small class="text-muted">
                        @if($isBalanced)
                            <i class="fas fa-check-circle text-success me-1"></i> Balanced
                        @else
                            <i class="fas fa-exclamation-triangle text-danger me-1"></i> Requires investigation
                        @endif
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-2 text-muted small">
        {{ $data->count() }} branch pairs &middot; Generated {{ now()->format('d-m-Y H:i') }}
    </div>
</div>

@section('head_meta')
<style>
@media print {
    .card { border: 1px solid #ddd !important; }
    .btn, .form-check, form { display: none !important; }
    .badge { border: 1px solid currentColor !important; }
    .table th, .table td { font-size: 0.75rem !important; padding: 2px 4px !important; }
    .table-active td, .table-light td { background: #f0f0f0 !important; -webkit-print-color-adjust: exact; }
    .table-dark td, .table-dark th { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; }
    .table-warning td { background: #fff3cd !important; -webkit-print-color-adjust: exact; }
}
</style>
@endsection
@endsection
