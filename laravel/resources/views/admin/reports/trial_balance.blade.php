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
            <p class="text-muted mb-0 small">
                Period: {{ $meta['from_date'] }} &rarr; {{ $meta['to_date'] }}
                @if($meta['branch_id']) &middot; Branch #{{ $meta['branch_id'] }} @endif
                &middot; Verifies Dr = Cr &middot; Opening + Period = Closing
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

    {{-- Integrity checks --}}
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center py-2">
                    <span class="small">Opening balanced</span>
                    @if ($checks['opening_balanced'])
                        <span class="badge bg-success"><i class="fas fa-check"></i> OK</span>
                    @else
                        <span class="badge bg-danger"><i class="fas fa-times"></i> {{ number_format(abs($checks['opening_diff']), 2) }}</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center py-2">
                    <span class="small">Period balanced</span>
                    @if ($checks['period_balanced'])
                        <span class="badge bg-success"><i class="fas fa-check"></i> OK</span>
                    @else
                        <span class="badge bg-danger"><i class="fas fa-times"></i> {{ number_format(abs($checks['period_diff']), 2) }}</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center py-2">
                    <span class="small">Closing balanced</span>
                    @if ($checks['closing_balanced'])
                        <span class="badge bg-success"><i class="fas fa-check"></i> OK</span>
                    @else
                        <span class="badge bg-danger"><i class="fas fa-times"></i> {{ number_format(abs($checks['closing_diff']), 2) }}</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center py-2">
                    <span class="small">All accounts balance</span>
                    @if ($checks['all_accounts_balance'])
                        <span class="badge bg-success"><i class="fas fa-check"></i> OK</span>
                    @else
                        <span class="badge bg-danger"><i class="fas fa-times"></i> {{ $checks['balance_check_fails'] }} fail(s)</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Sub-ledger reconciliation badges --}}
    @if (!empty($checks['subledger_reconciliation']))
    <div class="row g-2 mb-3">
        @foreach ($checks['subledger_reconciliation'] as $key => $sl)
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center py-2">
                    <span class="small">{{ $sl['label'] }}</span>
                    @if ($sl['reconciled'])
                        <span class="badge bg-success"><i class="fas fa-check"></i> Reconciled</span>
                    @else
                        <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> Diff: {{ number_format(abs($sl['difference']), 2) }}</span>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Orphaned lines warning --}}
    @if ($checks['orphaned_journal_lines'] > 0)
    <div class="alert alert-danger py-2 mb-3">
        <i class="fas fa-exclamation-triangle me-1"></i>
        <strong>{{ $checks['orphaned_journal_lines'] }}</strong> journal lines reference non-existent or inactive ledgers.
    </div>
    @endif

    {{-- Main Trial Balance Table --}}
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
                            <th class="text-end">Opening Bal</th>
                            <th class="text-end">Period Dr</th>
                            <th class="text-end">Period Cr</th>
                            <th class="text-end">Closing Dr</th>
                            <th class="text-end">Closing Cr</th>
                            <th class="text-end">Closing Bal</th>
                            <th class="text-center">GL</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grouped as $type => $rows)
                            <tr class="table-active">
                                <td colspan="12" class="fw-bold">
                                    <i class="fas fa-folder me-1"></i> {{ $type }}
                                    <span class="badge bg-secondary ms-1">{{ $rows->count() }}</span>
                                </td>
                            </tr>
                            @foreach ($rows as $r)
                                <tr>
                                    <td><code>{{ $r->ledger_code }}</code></td>
                                    <td>
                                        {{ $r->ledger_name }}
                                        @if($r->ledger_nature)
                                            <br><small class="text-muted">{{ $r->ledger_nature }}</small>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge bg-{{ $accountTypeBadge[$r->account_type] ?? 'secondary' }}-subtle text-{{ $accountTypeBadge[$r->account_type] ?? 'secondary' }}">
                                            {{ $r->account_type }}
                                        </span>
                                        @if($r->is_control_account)
                                            <span class="badge bg-dark-subtle text-dark ms-1" title="Control account">C</span>
                                        @endif
                                    </td>
                                    {{-- Opening Debit --}}
                                    <td class="text-end">{{ $r->opening_debit > 0.005 ? number_format($r->opening_debit, 2) : '&mdash;' }}</td>
                                    {{-- Opening Credit --}}
                                    <td class="text-end">{{ $r->opening_credit > 0.005 ? number_format($r->opening_credit, 2) : '&mdash;' }}</td>
                                    {{-- Opening Net Balance --}}
                                    <td class="text-end fw-semibold {{ $r->opening_balance > 0.005 ? ($r->opening_side === 'Dr' ? 'text-success' : 'text-danger') : '' }}">
                                        @if($r->opening_balance > 0.005)
                                            {{ number_format($r->opening_balance, 2) }}
                                            <small class="text-muted">{{ $r->opening_side }}</small>
                                        @else
                                            &mdash;
                                        @endif
                                    </td>
                                    {{-- Period Debit --}}
                                    <td class="text-end">{{ $r->period_debit > 0.005 ? number_format($r->period_debit, 2) : '&mdash;' }}</td>
                                    {{-- Period Credit --}}
                                    <td class="text-end">{{ $r->period_credit > 0.005 ? number_format($r->period_credit, 2) : '&mdash;' }}</td>
                                    {{-- Closing Debit --}}
                                    <td class="text-end">{{ $r->closing_debit > 0.005 ? number_format($r->closing_debit, 2) : '&mdash;' }}</td>
                                    {{-- Closing Credit --}}
                                    <td class="text-end">{{ $r->closing_credit > 0.005 ? number_format($r->closing_credit, 2) : '&mdash;' }}</td>
                                    {{-- Closing Net Balance --}}
                                    <td class="text-end fw-bold {{ $r->closing_balance > 0.005 ? ($r->closing_side === 'Dr' ? 'text-success' : 'text-danger') : '' }}">
                                        @if($r->closing_balance > 0.005)
                                            {{ number_format($r->closing_balance, 2) }}
                                            <small class="text-muted">{{ $r->closing_side }}</small>
                                        @else
                                            &mdash;
                                        @endif
                                    </td>
                                    {{-- GL drill-down link --}}
                                    <td class="text-center">
                                        <a href="{{ route('admin.reports.generalLedger', ['ledger_id' => $r->ledger_id, 'from_date' => $meta['from_date'], 'to_date' => $meta['to_date']]) }}"
                                           class="btn btn-sm btn-outline-primary py-0 px-1" title="View General Ledger">
                                            <i class="fas fa-book-open"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                            <tr class="table-light fw-semibold">
                                <td colspan="3" class="text-end">Subtotal &mdash; {{ $type }}</td>
                                <td class="text-end">{{ number_format($rows->sum('opening_debit'), 2) }}</td>
                                <td class="text-end">{{ number_format($rows->sum('opening_credit'), 2) }}</td>
                                <td class="text-end"></td>
                                <td class="text-end">{{ number_format($rows->sum('period_debit'), 2) }}</td>
                                <td class="text-end">{{ number_format($rows->sum('period_credit'), 2) }}</td>
                                <td class="text-end">{{ number_format($rows->sum('closing_debit'), 2) }}</td>
                                <td class="text-end">{{ number_format($rows->sum('closing_credit'), 2) }}</td>
                                <td class="text-end"></td>
                                <td></td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="3" class="text-end">GRAND TOTAL</td>
                            <td class="text-end">{{ number_format($totals['opening_debit'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['opening_credit'], 2) }}</td>
                            <td class="text-end"></td>
                            <td class="text-end">{{ number_format($totals['period_debit'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['period_credit'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['closing_debit'], 2) }}</td>
                            <td class="text-end">{{ number_format($totals['closing_credit'], 2) }}</td>
                            <td class="text-end"></td>
                            <td></td>
                        </tr>
                        <tr class="table-secondary">
                            <td colspan="3" class="text-end">Difference (Dr - Cr)</td>
                            <td class="text-end {{ abs($checks['opening_diff']) > 0.01 ? 'text-danger' : 'text-success' }}">{{ number_format($checks['opening_diff'], 2) }}</td>
                            <td class="text-end"></td>
                            <td class="text-end"></td>
                            <td class="text-end {{ abs($checks['period_diff']) > 0.01 ? 'text-danger' : 'text-success' }}">{{ number_format($checks['period_diff'], 2) }}</td>
                            <td class="text-end"></td>
                            <td class="text-end {{ abs($checks['closing_diff']) > 0.01 ? 'text-danger' : 'text-success' }}">{{ number_format($checks['closing_diff'], 2) }}</td>
                            <td class="text-end"></td>
                            <td class="text-end"></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- Sub-ledger reconciliation detail --}}
    @if (!empty($checks['subledger_reconciliation']))
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-light py-2">
            <h6 class="mb-0"><i class="fas fa-link me-1"></i> Sub-Ledger Reconciliation</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
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
    @endif

</div>
@endsection
