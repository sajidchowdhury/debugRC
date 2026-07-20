@extends('layouts.admin')

@php
    $accountTypeBadge = [
        'Asset'     => 'primary',
        'Liability' => 'warning',
        'Equity'    => 'info',
        'Income'    => 'success',
        'Expense'   => 'danger',
    ];
    $grouped = $data->groupBy('account_type');
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-scale-balanced me-2 text-primary"></i> Trial Balance</h2>
            <p class="text-muted mb-0 small">Opening, period &amp; closing balances per ledger — verifies Dr = Cr.</p>
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
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">Account type</label>
                    <select name="account_type" class="form-select form-select-sm">
                        <option value="">All types</option>
                        @foreach ($accountTypes as $t)
                            <option value="{{ $t }}" @selected(old('account_type', request('account_type', $meta['account_type'] ?? '')) === $t)>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-3 d-flex align-items-center pb-1">
                    <div class="form-check">
                        <input type="checkbox" name="include_zero" value="1" class="form-check-input" id="incZero"
                               @checked(old('include_zero', request('include_zero', $meta['include_zero'] ?? false)))>
                        <label class="form-check-label small" for="incZero">Include zero-balance ledgers</label>
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

    {{-- Integrity checks --}}
    <div class="row g-2 mb-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center py-2">
                    <span class="small">Opening balanced</span>
                    @if ($checks['opening_balanced'])
                        <span class="badge bg-success"><i class="fas fa-check"></i> OK</span>
                    @else
                        <span class="badge bg-danger"><i class="fas fa-times"></i> Out of balance</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center py-2">
                    <span class="small">Period balanced</span>
                    @if ($checks['period_balanced'])
                        <span class="badge bg-success"><i class="fas fa-check"></i> OK</span>
                    @else
                        <span class="badge bg-danger"><i class="fas fa-times"></i> Out of balance</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center py-2">
                    <span class="small">Closing balanced</span>
                    @if ($checks['closing_balanced'])
                        <span class="badge bg-success"><i class="fas fa-check"></i> OK</span>
                    @else
                        <span class="badge bg-danger"><i class="fas fa-times"></i> Out of balance</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Ledger</th>
                            <th>Type</th>
                            <th class="text-end">Opening Dr</th>
                            <th class="text-end">Opening Cr</th>
                            <th class="text-end">Period Dr</th>
                            <th class="text-end">Period Cr</th>
                            <th class="text-end">Closing Dr</th>
                            <th class="text-end">Closing Cr</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grouped as $type => $rows)
                            <tr class="table-active">
                                <td colspan="9" class="fw-bold">
                                    <i class="fas fa-folder me-1"></i> {{ $type }}
                                    <span class="badge bg-secondary ms-1">{{ $rows->count() }}</span>
                                </td>
                            </tr>
                            @foreach ($rows as $r)
                                <tr>
                                    <td><code>{{ $r->ledger_code }}</code></td>
                                    <td>{{ $r->ledger_name }}<br><small class="text-muted">{{ $r->ledger_nature }}</small></td>
                                    <td><span class="badge bg-{{ $accountTypeBadge[$r->account_type] ?? 'secondary' }}-subtle text-{{ $accountTypeBadge[$r->account_type] ?? 'secondary' }}">{{ $r->account_type }}</span></td>
                                    <td class="text-end">{{ number_format($r->opening_debit, 2) }}</td>
                                    <td class="text-end">{{ number_format($r->opening_credit, 2) }}</td>
                                    <td class="text-end">{{ number_format($r->period_debit, 2) }}</td>
                                    <td class="text-end">{{ number_format($r->period_credit, 2) }}</td>
                                    <td class="text-end fw-semibold">{{ number_format($r->closing_debit, 2) }}</td>
                                    <td class="text-end fw-semibold">{{ number_format($r->closing_credit, 2) }}</td>
                                </tr>
                            @endforeach
                            <tr class="table-light fw-semibold">
                                <td colspan="3" class="text-end">Subtotal — {{ $type }}</td>
                                <td class="text-end">{{ number_format($rows->sum('opening_debit'), 2) }}</td>
                                <td class="text-end">{{ number_format($rows->sum('opening_credit'), 2) }}</td>
                                <td class="text-end">{{ number_format($rows->sum('period_debit'), 2) }}</td>
                                <td class="text-end">{{ number_format($rows->sum('period_credit'), 2) }}</td>
                                <td class="text-end">{{ number_format($rows->sum('closing_debit'), 2) }}</td>
                                <td class="text-end">{{ number_format($rows->sum('closing_credit'), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="3" class="text-end">GRAND TOTAL</td>
                            <td class="text-end">{{ number_format($totals['opening_debit'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['opening_credit'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['period_debit'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['period_credit'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['closing_debit'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['closing_credit'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
