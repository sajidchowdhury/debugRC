@extends('layouts.admin')

@php
    // Group data by parent_group for the Tally-style display
    $grouped = $data->groupBy('parent_group');
    $allGroups = $data->pluck('parent_group', 'parent_group')->sort()->keys();
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Header bar --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-scale-balanced me-2 text-primary"></i> Trial Balance</h2>
            <p class="text-muted mb-0 small">
                From {{ \Carbon\Carbon::parse($meta['from_date'])->format('d-m-Y') }} to {{ \Carbon\Carbon::parse($meta['to_date'])->format('d-m-Y') }}
                @if($meta['branch_id']) &middot; Branch #{{ $meta['branch_id'] }} @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.trialBalance', array_merge(request()->all(), ['export' => 'csv'])) }}"
               class="btn btn-outline-success btn-sm">
                <i class="fas fa-file-csv me-1"></i> CSV
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-print me-1"></i> Print
            </button>
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.trialBalance') }}" class="row g-2 align-items-end">
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
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">Account type</label>
                    <select name="account_type" class="form-select form-select-sm">
                        <option value="">All types</option>
                        @foreach ($accountTypes as $t)
                            <option value="{{ $t }}" @selected(old('account_type', request('account_type', $meta['account_type'] ?? '')) === $t)>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected(old('branch_id', request('branch_id', $meta['branch_id'] ?? '')) == $b->id)>{{ $b->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-2 d-flex align-items-center pb-1">
                    <div class="form-check">
                        <input type="checkbox" name="include_zero" value="1" class="form-check-input" id="incZero"
                               @checked(old('include_zero', request('include_zero', $meta['include_zero'] ?? false)))>
                        <label class="form-check-label small" for="incZero">Include zero-balance</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Integrity badges (compact) --}}
    <div class="d-flex gap-2 mb-3 flex-wrap">
        <span class="badge {{ $checks['opening_balanced'] ? 'bg-success' : 'bg-danger' }} py-2 px-3">
            <i class="fas fa-{{ $checks['opening_balanced'] ? 'check' : 'times' }} me-1"></i>
            Opening {{ $checks['opening_balanced'] ? 'Balanced' : 'Diff: ' . number_format(abs($checks['opening_diff']), 2) }}
        </span>
        <span class="badge {{ $checks['period_balanced'] ? 'bg-success' : 'bg-danger' }} py-2 px-3">
            <i class="fas fa-{{ $checks['period_balanced'] ? 'check' : 'times' }} me-1"></i>
            Period {{ $checks['period_balanced'] ? 'Balanced' : 'Diff: ' . number_format(abs($checks['period_diff']), 2) }}
        </span>
        <span class="badge {{ $checks['closing_balanced'] ? 'bg-success' : 'bg-danger' }} py-2 px-3">
            <i class="fas fa-{{ $checks['closing_balanced'] ? 'check' : 'times' }} me-1"></i>
            Closing {{ $checks['closing_balanced'] ? 'Balanced' : 'Diff: ' . number_format(abs($checks['closing_diff']), 2) }}
        </span>
        @if ($checks['orphaned_journal_lines'] > 0)
        <span class="badge bg-danger py-2 px-3">
            <i class="fas fa-exclamation-triangle me-1"></i> {{ $checks['orphaned_journal_lines'] }} orphaned lines
        </span>
        @endif
    </div>

    {{-- Main Trial Balance Table — Tally-style 6 columns --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size: 0.85rem;">
                    <thead>
                        <tr class="table-dark">
                            <th style="min-width: 200px;">Account</th>
                            <th style="min-width: 150px;">Parent Group</th>
                            <th class="text-end" style="min-width: 130px;">Opening Bal.</th>
                            <th class="text-end" style="min-width: 130px;">Debit</th>
                            <th class="text-end" style="min-width: 130px;">Credit</th>
                            <th class="text-end" style="min-width: 130px;">Closing Bal.</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grouped as $parentGroup => $rows)
                            {{-- Parent Group header row --}}
                            <tr class="table-active">
                                <td colspan="6" class="fw-bold py-1">
                                    <i class="fas fa-folder me-1 text-muted"></i> {{ $parentGroup ?? 'Ungrouped' }}
                                </td>
                            </tr>
                            @foreach ($rows as $r)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.reports.generalLedger', ['ledger_id' => $r->ledger_id, 'from_date' => $meta['from_date'], 'to_date' => $meta['to_date']]) }}"
                                       class="text-decoration-none" title="View General Ledger">
                                        {{ $r->ledger_name }}
                                    </a>
                                    <small class="text-muted ms-1">[{{ $r->ledger_code }}]</small>
                                </td>
                                <td class="text-muted">{{ $r->parent_group ?? '—' }}</td>
                                {{-- Opening Balance: net with Dr/Cr suffix --}}
                                <td class="text-end">
                                    @if($r->opening_balance > 0.005)
                                        {{ number_format($r->opening_balance, 2) }} <small class="fw-bold {{ $r->opening_side === 'Dr' ? 'text-success' : 'text-danger' }}">{{ $r->opening_side }}</small>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                {{-- Period Debit --}}
                                <td class="text-end">
                                    @if($r->period_debit > 0.005)
                                        {{ number_format($r->period_debit, 2) }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                {{-- Period Credit --}}
                                <td class="text-end">
                                    @if($r->period_credit > 0.005)
                                        {{ number_format($r->period_credit, 2) }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                {{-- Closing Balance: net with Dr/Cr suffix --}}
                                <td class="text-end fw-semibold">
                                    @if($r->closing_balance > 0.005)
                                        {{ number_format($r->closing_balance, 2) }} <small class="fw-bold {{ $r->closing_side === 'Dr' ? 'text-success' : 'text-danger' }}">{{ $r->closing_side }}</small>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                    <tfoot>
                        {{-- Grand Total row --}}
                        <tr class="table-dark fw-bold">
                            <td colspan="2" class="text-end">Grand Total</td>
                            <td class="text-end">
                                {{ number_format(abs($totals['opening_debit'] - $totals['opening_credit']), 2) }}
                                <small>{{ ($totals['opening_debit'] - $totals['opening_credit']) >= 0 ? 'Dr' : 'Cr' }}</small>
                            </td>
                            <td class="text-end">{{ number_format($totals['period_debit'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['period_credit'], 2) }}</td>
                            <td class="text-end">
                                {{ number_format(abs($totals['closing_debit'] - $totals['closing_credit']), 2) }}
                                <small>{{ ($totals['closing_debit'] - $totals['closing_credit']) >= 0 ? 'Dr' : 'Cr' }}</small>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- Sub-ledger reconciliation detail (collapsible) --}}
    @if (!empty($checks['subledger_reconciliation']))
    <div class="mt-3">
        <a class="text-muted small" data-bs-toggle="collapse" href="#subledgerRecon" role="button" aria-expanded="false">
            <i class="fas fa-link me-1"></i> Sub-Ledger Reconciliation Details
            <i class="fas fa-chevron-down ms-1"></i>
        </a>
        <div class="collapse mt-2" id="subledgerRecon">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Control Account</th>
                                <th class="text-end">GL Balance</th>
                                <th class="text-end">Sub-Ledger Balance</th>
                                <th class="text-end">Difference</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($checks['subledger_reconciliation'] as $key => $sl)
                            <tr class="{{ $sl['reconciled'] ? '' : 'table-warning' }}">
                                <td>{{ $sl['label'] }}</td>
                                <td class="text-end">{{ number_format($sl['gl_balance'], 2) }}</td>
                                <td class="text-end">{{ number_format($sl['sub_balance'], 2) }}</td>
                                <td class="text-end {{ abs($sl['difference']) > 0.01 ? 'text-danger fw-bold' : '' }}">{{ number_format($sl['difference'], 2) }}</td>
                                <td class="text-center">
                                    @if($sl['reconciled'])
                                        <span class="badge bg-success"><i class="fas fa-check"></i> OK</span>
                                    @else
                                        <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> Out of balance</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Account count info --}}
    <div class="mt-2 text-muted small">
        {{ $data->count() }} accounts shown &middot; Generated {{ now()->format('d-m-Y H:i') }}
    </div>

</div>

{{-- Print styles --}}
@push('styles')
<style>
@media print {
    .card { border: 1px solid #ddd !important; }
    .btn, .form-check, form { display: none !important; }
    .badge { border: 1px solid currentColor !important; }
    .table th, .table td { font-size: 0.75rem !important; padding: 2px 4px !important; }
    .table-active td { background: #f0f0f0 !important; -webkit-print-color-adjust: exact; }
    .table-dark td, .table-dark th { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; }
}
</style>
@endpush
@endsection
