@extends('layouts.admin')

@section('content')
@php
    $modeBadge = function (string $mode) use ($transaction): string {
        $cls = ' fs-6';
        return [
            'cash'            => '<span class="badge bg-secondary' . $cls . '"><i class="fas fa-money-bill me-1"></i>Cash</span>',
            'bank'            => '<span class="badge bg-primary' . $cls . '"><i class="fas fa-university me-1"></i>Bank</span>',
            'mobile_banking' => '<span class="badge bg-info' . $cls . '"><i class="fas fa-mobile-screen me-1"></i>Mobile</span>',
            'cheque'          => '<span class="badge bg-warning text-dark' . $cls . '"><i class="fas fa-money-check me-1"></i>Cheque</span>',
            'adjustment'      => '<span class="badge bg-dark' . $cls . '"><i class="fas fa-sliders me-1"></i>Adjustment</span>',
        ][$mode] ?? '<span class="badge bg-light text-dark' . $cls . '">' . e($mode) . '</span>';
    };

    $statusBadge = function (bool $large = false) use ($transaction): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        if ($transaction->is_reversed) {
            return '<span class="badge bg-danger' . $cls . '"><i class="fas fa-rotate-left me-1"></i>Reversed</span>';
        }
        return '<span class="badge bg-success' . $cls . '"><i class="fas fa-check me-1"></i>Active</span>';
    };

    $typeBadge = function () use ($transaction): string {
        $type = $transaction->transaction_type ?? 'advance';
        $badges = [
            'advance'    => '<span class="badge bg-success fs-6"><i class="fas fa-hand-holding-dollar me-1"></i>Employee Advance</span>',
            'loan'       => '<span class="badge bg-warning text-dark fs-6"><i class="fas fa-landmark me-1"></i>Employee Loan</span>',
            'salary'     => '<span class="badge bg-primary fs-6"><i class="fas fa-money-bills me-1"></i>Salary Payment</span>',
            'repayment'  => '<span class="badge fs-6" style="background:#0d9488;color:#fff;"><i class="fas fa-arrow-rotate-left me-1"></i>Employee Repayment</span>',
            'deduction'  => '<span class="badge bg-purple fs-6" style="background:#7c3aed;color:#fff;"><i class="fas fa-minus-circle me-1"></i>Deduction</span>',
            'adjustment' => '<span class="badge bg-dark fs-6"><i class="fas fa-sliders me-1"></i>Adjustment</span>',
        ];
        return $badges[$type] ?? '<span class="badge bg-light text-dark fs-6">' . e($type) . '</span>';
    };

    $typeGradients = [
        'advance'    => 'linear-gradient(135deg,#d97706,#b45309)',
        'loan'       => 'linear-gradient(135deg,#d97706,#b45309)',
        'salary'     => 'linear-gradient(135deg,#d97706,#b45309)',
        'adjustment' => 'linear-gradient(135deg,#d97706,#b45309)',
        'repayment'  => 'linear-gradient(135deg,#059669,#0d9488)',
        'deduction'  => 'linear-gradient(135deg,#059669,#0d9488)',
    ];
    $heroGradient = $typeGradients[$transaction->transaction_type ?? 'advance'] ?? $typeGradients['advance'];

    $typeIcons = [
        'advance'    => 'fa-hand-holding-dollar',
        'loan'       => 'fa-landmark',
        'salary'     => 'fa-money-bills',
        'repayment'  => 'fa-arrow-rotate-left',
        'deduction'  => 'fa-minus-circle',
        'adjustment' => 'fa-sliders',
    ];
    $heroIcon = $typeIcons[$transaction->transaction_type ?? 'advance'] ?? $typeIcons['advance'];

    $isInflow = !$transaction->isOutflow();
@endphp

<style>
    .et-show-page { --et-primary: #d97706; --et-primary-dark: #b45309; --et-accent: #059669; }

    /* Hero */
    .et-show-hero {
        background: linear-gradient(135deg, var(--et-primary), var(--et-primary-dark));
        border-radius: 1rem;
        padding: 1.5rem 1.75rem;
        color: #fff;
        box-shadow: 0 8px 32px rgba(217,119,6,0.18);
        margin-bottom: 1.5rem;
    }
    .et-show-hero.inflow-hero {
        background: linear-gradient(135deg, #059669, #0d9488);
        box-shadow: 0 8px 32px rgba(5,150,105,0.18);
    }
    .et-show-hero h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: 0.15rem; }
    .et-show-hero .et-hero-subtitle { font-size: 0.82rem; opacity: 0.85; }
    .et-show-hero .et-hero-amount {
        font-size: 2rem; font-weight: 800; font-variant-numeric: tabular-nums;
        line-height: 1.1;
    }

    /* Section cards */
    .et-section-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 0.875rem;
        box-shadow: 0 1px 4px rgba(15,23,42,0.04);
        margin-bottom: 1.25rem;
        overflow: hidden;
        transition: box-shadow 0.2s;
    }
    .et-section-card:hover { box-shadow: 0 4px 16px rgba(15,23,42,0.07); }
    .et-section-header {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.75rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .et-section-header h2 { font-size: 0.88rem; font-weight: 700; margin: 0; color: #0f172a; }
    .et-section-header .et-section-icon {
        width: 32px; height: 32px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.85rem; color: #fff;
    }
    .et-section-body { padding: 1.25rem; }

    /* Detail grid items */
    .et-detail-item {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 0.75rem 1rem;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .et-detail-item:hover { border-color: #cbd5e1; box-shadow: 0 2px 8px rgba(15,23,42,0.04); }
    .et-detail-label {
        font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em;
        color: #64748b; margin-bottom: 0.2rem; font-weight: 600;
    }
    .et-detail-value { font-weight: 600; color: #0f172a; font-size: 0.92rem; }

    /* Amount highlight card */
    .et-amount-card {
        background: linear-gradient(135deg, var(--et-primary), var(--et-primary-dark));
        border-radius: 0.875rem;
        padding: 1.5rem;
        color: #fff;
        text-align: center;
        box-shadow: 0 8px 24px rgba(217,119,6,0.2);
    }
    .et-amount-card.inflow-card {
        background: linear-gradient(135deg, #059669, #0d9488);
        box-shadow: 0 8px 24px rgba(5,150,105,0.2);
    }
    .et-amount-card .et-amount-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.85; }
    .et-amount-card .et-amount-value { font-size: 2rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .et-amount-card .et-amount-meta { font-size: 0.78rem; opacity: 0.8; margin-top: 0.25rem; }

    /* Due card */
    .et-due-card {
        border-radius: 0.875rem;
        padding: 1rem;
        text-align: center;
        border: 1px solid #fde68a;
        background: linear-gradient(135deg, #fffbeb, #fff7ed);
    }
    .et-due-card.zero-due {
        border-color: #bbf7d0;
        background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
    }
    .et-due-card .et-due-amount {
        font-size: 1.5rem; font-weight: 800; font-variant-numeric: tabular-nums;
    }
    .et-due-card .et-due-label { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }

    /* Reversal banner */
    .et-reversal-banner {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-left: 4px solid #dc2626;
        border-radius: 0.875rem;
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
    }

    /* GL table */
    .et-gl-table th {
        font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em;
        color: #64748b; font-weight: 600;
    }
    .et-gl-table td { font-size: 0.88rem; }
    .et-gl-table .debit-col { color: #0d9488; font-weight: 600; }
    .et-gl-table .credit-col { color: #dc2626; font-weight: 600; }

    /* Action button bar */
    .et-action-bar {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 0.875rem;
        box-shadow: 0 1px 4px rgba(15,23,42,0.04);
        padding: 1rem 1.25rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: center;
        position: sticky;
        bottom: 1rem;
        z-index: 10;
    }

    /* GL mini cards */
    .et-gl-mini-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 0.75rem;
        text-align: center;
    }
    .et-gl-mini-card .et-gl-mini-label { font-size: 0.7rem; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em; }
    .et-gl-mini-card .et-gl-mini-value { font-weight: 700; font-size: 0.82rem; color: #0f172a; }

    /* Detail grid */
    .et-detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 1rem;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .et-show-hero { padding: 1rem 1.15rem; border-radius: 0.75rem; }
        .et-show-hero h1 { font-size: 1.1rem; }
        .et-show-hero .et-hero-amount { font-size: 1.5rem; }
        .et-section-body { padding: 0.85rem; }
        .et-amount-card .et-amount-value { font-size: 1.5rem; }
        .et-action-bar { position: static; border-radius: 0.75rem; }
    }
</style>

<div class="container-fluid py-3 et-show-page">
    {{-- Hero header --}}
    <header class="et-show-hero {{ $isInflow ? 'inflow-hero' : '' }}" id="etShowHero">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1>
                    <i class="fas {{ $heroIcon }} me-2"></i>{{ $transaction->getTransactionTypeLabel() }} {{ $transaction->transaction_code }}
                </h1>
                <p class="et-hero-subtitle mb-2">
                    @if ($transaction->employee)<i class="fas fa-user me-1"></i> {{ $transaction->employee->name }}@endif
                    @if ($transaction->branch) · <i class="fas fa-code-branch me-1"></i> {{ $transaction->branch->branch_name }}@endif
                    · <i class="fas fa-calendar me-1"></i> {{ \Carbon\Carbon::parse($transaction->transaction_date)->format('d M Y') }}
                </p>
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    {!! $statusBadge() !!}
                    {!! $typeBadge() !!}
                </div>
            </div>
            <div class="text-end">
                <div class="et-hero-amount">Tk {{ number_format((float) $transaction->amount, 2) }}</div>
                <div class="et-hero-subtitle">
                    @if ($transaction->isOutflow())
                        Outflow — increases payable
                    @else
                        Inflow — reduces payable
                    @endif
                </div>
                <div class="d-flex gap-2 mt-2 justify-content-end">
                    <a href="{{ route('admin.employee-transactions.slip', ['id' => $transaction->id]) }}" class="btn btn-outline-light btn-sm" target="_blank">
                        <i class="fas fa-print me-1"></i> Print
                    </a>
                    <a href="{{ route('admin.employee-transactions.index') }}" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </header>

    {{-- Reversal alert --}}
    @if ($transaction->is_reversed)
        <div class="et-reversal-banner">
            <div class="d-flex align-items-start gap-2">
                <i class="fas fa-rotate-left fa-lg text-danger mt-1"></i>
                <div class="flex-grow-1">
                    <strong class="text-danger">This transaction has been reversed.</strong>
                    <div class="mt-1 small">
                        @if ($transaction->reversed_at)
                            <span class="me-3"><i class="fas fa-calendar me-1"></i>
                                Reversed at: {{ \Carbon\Carbon::parse($transaction->reversed_at)->format('d M Y H:i') }}
                            </span>
                        @endif
                        @if ($transaction->reversed_by)
                            <span class="me-3"><i class="fas fa-user me-1"></i>
                                By: User #{{ $transaction->reversed_by }}
                            </span>
                        @endif
                    </div>
                    @if (!empty($transaction->reverse_reason))
                        <div class="mt-1">
                            <span class="text-muted">Reason:</span>
                            <em>{{ $transaction->reverse_reason }}</em>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- Left: main details --}}
        <div class="col-lg-8">
            {{-- Transaction details section card --}}
            <div class="et-section-card">
                <div class="et-section-header">
                    <div class="et-section-icon" style="background:linear-gradient(135deg,#d97706,#b45309);">
                        <i class="fas fa-circle-info"></i>
                    </div>
                    <h2>Transaction Details</h2>
                </div>
                <div class="et-section-body">
                    <div class="et-detail-grid">
                        <div class="et-detail-item">
                            <div class="et-detail-label">Transaction Code</div>
                            <div class="et-detail-value">
                                <span class="badge bg-secondary-subtle text-secondary" style="font-size:0.85rem;">{{ $transaction->transaction_code }}</span>
                            </div>
                        </div>
                        <div class="et-detail-item">
                            <div class="et-detail-label">Transaction Type</div>
                            <div class="et-detail-value">
                                {!! $typeBadge() !!}
                                <div class="small text-muted mt-1">{{ $transaction->getGlDescription() }}</div>
                            </div>
                        </div>
                        <div class="et-detail-item">
                            <div class="et-detail-label">Transaction Date</div>
                            <div class="et-detail-value">
                                <i class="fas fa-calendar me-1 text-muted"></i>
                                {{ \Carbon\Carbon::parse($transaction->transaction_date)->format('d M Y') }}
                            </div>
                        </div>
                        <div class="et-detail-item">
                            <div class="et-detail-label">Employee</div>
                            <div class="et-detail-value">
                                @if ($transaction->employee)
                                    <i class="fas fa-user me-1 text-muted"></i>
                                    {{ $transaction->employee->name }}
                                    <span class="text-muted small">({{ $transaction->employee->employee_code }})</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </div>
                        </div>
                        <div class="et-detail-item">
                            <div class="et-detail-label">Branch</div>
                            <div class="et-detail-value">
                                @if ($transaction->branch)
                                    <i class="fas fa-code-branch me-1 text-muted"></i>
                                    {{ $transaction->branch->branch_name }}
                                    <span class="text-muted small">({{ $transaction->branch->branch_code }})</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </div>
                        </div>
                        <div class="et-detail-item">
                            <div class="et-detail-label">Payment Mode</div>
                            <div class="et-detail-value">{!! $modeBadge($transaction->payment_mode) !!}</div>
                        </div>
                        @if ($transaction->isBankMode() || $transaction->bank)
                            <div class="et-detail-item">
                                <div class="et-detail-label">Bank</div>
                                <div class="et-detail-value">
                                    @if ($transaction->bank)
                                        <i class="fas fa-university me-1 text-muted"></i>
                                        {{ $transaction->bank->bank_name }}
                                        @if (!empty($transaction->bank->bank_code))
                                            <span class="text-muted small">({{ $transaction->bank->bank_code }})</span>
                                        @endif
                                        @if (!empty($transaction->bank->account_no))
                                            <div class="small text-muted mt-1"><i class="fas fa-hashtag me-1"></i>A/C: {{ $transaction->bank->account_no }}</div>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </div>
                            </div>
                        @endif
                        @if ($transaction->collectedBy)
                            <div class="et-detail-item">
                                <div class="et-detail-label">Collected By</div>
                                <div class="et-detail-value">
                                    <i class="fas fa-user me-1 text-muted"></i>
                                    {{ $transaction->collectedBy->name ?? $transaction->collectedBy->employee_code ?? 'Employee #' . $transaction->collected_by }}
                                </div>
                            </div>
                        @endif
                        <div class="et-detail-item">
                            <div class="et-detail-label">Amount</div>
                            <div class="et-detail-value">
                                @if ($transaction->isOutflow())
                                    <span class="text-danger">Tk {{ number_format((float) $transaction->amount, 2) }}</span>
                                    <span class="text-muted small ms-1">(outflow)</span>
                                @else
                                    <span class="text-success">Tk {{ number_format((float) $transaction->amount, 2) }}</span>
                                    <span class="text-muted small ms-1">(inflow)</span>
                                @endif
                            </div>
                        </div>
                        @if (!empty($transaction->reference_no))
                            <div class="et-detail-item">
                                <div class="et-detail-label">Reference No</div>
                                <div class="et-detail-value">
                                    <span class="badge bg-info-subtle text-info">{{ $transaction->reference_no }}</span>
                                </div>
                            </div>
                        @endif
                        <div class="et-detail-item">
                            <div class="et-detail-label">Description</div>
                            <div class="et-detail-value">{!! nl2br(e($transaction->description ?: '—')) !!}</div>
                        </div>
                        <div class="et-detail-item">
                            <div class="et-detail-label">Created By</div>
                            <div class="et-detail-value small text-muted">
                                @if ($transaction->created_by) User #{{ $transaction->created_by }} @else — @endif
                                @if ($transaction->created_at) · {{ $transaction->created_at->format('d M Y H:i') }} @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- GL Journal Entry card --}}
            @if ($transaction->journalEntry)
                @php
                    $je           = $transaction->journalEntry;
                    $jeTotalDr    = 0;
                    $jeTotalCr    = 0;
                    foreach ($je->lines as $line) {
                        $jeTotalDr += (float) $line->debit;
                        $jeTotalCr += (float) $line->credit;
                    }
                @endphp
                <div class="et-section-card">
                    <div class="et-section-header">
                        <div class="et-section-icon" style="background:linear-gradient(135deg,#0f172a,#334155);">
                            <i class="fas fa-book"></i>
                        </div>
                        <h2>GL Journal Entry</h2>
                        @if ($je->is_reversed)
                            <span class="badge bg-danger-subtle text-danger ms-auto">
                                <i class="fas fa-rotate-left me-1"></i>Reversed
                            </span>
                        @endif
                    </div>
                    <div class="et-section-body">
                        <div class="et-detail-grid mb-3">
                            <div class="et-detail-item">
                                <div class="et-detail-label">JE #</div>
                                <div class="et-detail-value">
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $je->entry_no }}</span>
                                </div>
                            </div>
                            <div class="et-detail-item">
                                <div class="et-detail-label">Date</div>
                                <div class="et-detail-value">
                                    {{ \Carbon\Carbon::parse($je->entry_date)->format('d M Y') }}
                                </div>
                            </div>
                            <div class="et-detail-item">
                                <div class="et-detail-label">Description</div>
                                <div class="et-detail-value">{{ $je->description ?: '—' }}</div>
                            </div>
                            @if (!empty($je->source))
                                <div class="et-detail-item">
                                    <div class="et-detail-label">Source</div>
                                    <div class="et-detail-value">{{ $je->source }}</div>
                                </div>
                            @endif
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0 et-gl-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Ledger</th>
                                        <th class="text-end">Debit (Tk)</th>
                                        <th class="text-end">Credit (Tk)</th>
                                        <th>Memo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($je->lines as $line)
                                        <tr>
                                            <td>
                                                @if ($line->ledger)
                                                    <span class="fw-semibold">{{ $line->ledger->ledger_name }}</span>
                                                    @if (!empty($line->ledger->ledger_code))
                                                        <div class="small text-muted">{{ $line->ledger->ledger_code }}</div>
                                                    @endif
                                                @else
                                                    <span class="text-muted">Ledger #{{ $line->ledger_id }}</span>
                                                @endif
                                            </td>
                                            <td class="text-end debit-col">
                                                @if ((float) $line->debit > 0)
                                                    {{ number_format((float) $line->debit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end credit-col">
                                                @if ((float) $line->credit > 0)
                                                    {{ number_format((float) $line->credit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="small text-muted">{{ $line->memo ?: '—' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-3">No lines.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td class="text-end">Total</td>
                                        <td class="text-end text-teal">{{ number_format($jeTotalDr, 2) }}</td>
                                        <td class="text-end text-danger">{{ number_format($jeTotalCr, 2) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Intercompany GL Journal card --}}
            @if ($transaction->intercompanyJournalEntry)
                @php
                    $icje        = $transaction->intercompanyJournalEntry;
                    $icTotalDr   = 0;
                    $icTotalCr   = 0;
                    foreach ($icje->lines as $line) {
                        $icTotalDr += (float) $line->debit;
                        $icTotalCr += (float) $line->credit;
                    }
                @endphp
                <div class="et-section-card">
                    <div class="et-section-header">
                        <div class="et-section-icon" style="background:linear-gradient(135deg,#0ea5e9,#0284c7);">
                            <i class="fas fa-right-left"></i>
                        </div>
                        <h2>Intercompany GL Journal</h2>
                        @if ($icje->is_reversed)
                            <span class="badge bg-danger-subtle text-danger ms-auto">
                                <i class="fas fa-rotate-left me-1"></i>Reversed
                            </span>
                        @endif
                    </div>
                    <div class="et-section-body">
                        <div class="et-detail-grid mb-3">
                            <div class="et-detail-item">
                                <div class="et-detail-label">JE #</div>
                                <div class="et-detail-value">
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $icje->entry_no }}</span>
                                </div>
                            </div>
                            <div class="et-detail-item">
                                <div class="et-detail-label">Date</div>
                                <div class="et-detail-value">
                                    {{ \Carbon\Carbon::parse($icje->entry_date)->format('d M Y') }}
                                </div>
                            </div>
                            <div class="et-detail-item">
                                <div class="et-detail-label">Description</div>
                                <div class="et-detail-value">{{ $icje->description ?: '—' }}</div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0 et-gl-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Ledger</th>
                                        <th class="text-end">Debit (Tk)</th>
                                        <th class="text-end">Credit (Tk)</th>
                                        <th>Memo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($icje->lines as $line)
                                        <tr>
                                            <td>
                                                @if ($line->ledger)
                                                    <span class="fw-semibold">{{ $line->ledger->ledger_name }}</span>
                                                    @if (!empty($line->ledger->ledger_code))
                                                        <div class="small text-muted">{{ $line->ledger->ledger_code }}</div>
                                                    @endif
                                                @else
                                                    <span class="text-muted">Ledger #{{ $line->ledger_id }}</span>
                                                @endif
                                            </td>
                                            <td class="text-end debit-col">
                                                @if ((float) $line->debit > 0)
                                                    {{ number_format((float) $line->debit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end credit-col">
                                                @if ((float) $line->credit > 0)
                                                    {{ number_format((float) $line->credit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="small text-muted">{{ $line->memo ?: '—' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-3">No lines.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td class="text-end">Total</td>
                                        <td class="text-end text-teal">{{ number_format($icTotalDr, 2) }}</td>
                                        <td class="text-end text-danger">{{ number_format($icTotalCr, 2) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Employee Ledger Entries card --}}
            @if ($employeeLedgerEntries->isNotEmpty())
                <div class="et-section-card">
                    <div class="et-section-header">
                        <div class="et-section-icon" style="background:linear-gradient(135deg,#c2410c,#ea580c);">
                            <i class="fas fa-list"></i>
                        </div>
                        <h2>Employee Ledger Entries</h2>
                        <span class="badge bg-success-subtle text-success ms-auto">{{ $employeeLedgerEntries->count() }}</span>
                    </div>
                    <div class="et-section-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0 et-gl-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th class="text-end">Debit (Tk)</th>
                                        <th class="text-end">Credit (Tk)</th>
                                        <th class="text-end">Balance (Tk)</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($employeeLedgerEntries as $ele)
                                        <tr>
                                            <td class="small text-nowrap">
                                                @if (!empty($ele->transaction_date))
                                                    {{ \Carbon\Carbon::parse($ele->transaction_date)->format('d M Y') }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">{{ $ele->transaction_type ?? '—' }}</span>
                                            </td>
                                            <td class="text-end debit-col">
                                                @if (!empty($ele->debit) && (float) $ele->debit > 0)
                                                    {{ number_format((float) $ele->debit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end credit-col">
                                                @if (!empty($ele->credit) && (float) $ele->credit > 0)
                                                    {{ number_format((float) $ele->credit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end fw-semibold running-bal">
                                                @if (!empty($ele->running_balance))
                                                    {{ number_format((float) $ele->running_balance, 2) }}
                                                @elseif (!empty($ele->balance))
                                                    {{ number_format((float) $ele->balance, 2) }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="small text-muted">
                                                {{ $ele->description ?: '—' }}
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

        {{-- Right: aside --}}
        <div class="col-lg-4">
            {{-- Amount highlight card --}}
            <div class="et-amount-card {{ $isInflow ? 'inflow-card' : '' }} mb-3">
                <div class="et-amount-label">
                    @if ($transaction->isOutflow())
                        {{ $transaction->getTransactionTypeLabel() }} Amount
                    @else
                        {{ $transaction->getTransactionTypeLabel() }} Amount
                    @endif
                </div>
                <div class="et-amount-value">Tk {{ number_format((float) $transaction->amount, 2) }}</div>
                <div class="et-amount-meta">
                    <i class="fas fa-calendar me-1"></i>
                    {{ \Carbon\Carbon::parse($transaction->transaction_date)->format('d M Y') }}
                </div>
            </div>

            {{-- Status card --}}
            <div class="et-section-card">
                <div class="et-section-header">
                    <div class="et-section-icon" style="background:linear-gradient(135deg,{{ $transaction->is_reversed ? '#dc2626,#b91c1c' : '#059669,#047857' }});">
                        <i class="fas fa-flag"></i>
                    </div>
                    <h2>Status</h2>
                </div>
                <div class="et-section-body text-center">
                    <div class="mb-2">{!! $statusBadge(true) !!}</div>
                    <div class="small text-muted">
                        @if ($transaction->is_reversed)
                            Reversed — GL and employee ledger have been backed out.
                        @else
                            Active — GL posted, employee ledger updated.
                        @endif
                    </div>
                </div>
            </div>

            {{-- Employee payable card --}}
            <div class="et-section-card">
                <div class="et-section-header">
                    <div class="et-section-icon" style="background:linear-gradient(135deg,#b45309,#d97706);">
                        <i class="fas fa-taka-sign"></i>
                    </div>
                    <h2>Employee Payable</h2>
                </div>
                <div class="et-section-body">
                    <div class="et-due-card {{ $employeeDue <= 0 ? 'zero-due' : '' }}">
                        <div class="et-due-amount" style="color: {{ $employeeDue > 0 ? '#92400e' : '#065f46' }};">
                            Tk {{ number_format((float) $employeeDue, 2) }}
                        </div>
                        <div class="et-due-label" style="color: {{ $employeeDue > 0 ? '#92400e' : '#065f46' }};">Current Balance Due</div>
                    </div>
                    <div class="small text-muted text-center mt-2">
                        @if ($employeeDue > 0)
                            <i class="fas fa-arrow-up me-1"></i> We owe this employee
                        @elseif ($employeeDue < 0)
                            <i class="fas fa-arrow-down me-1"></i> Advance balance (employee owes us)
                        @else
                            <i class="fas fa-check me-1"></i> Settled
                        @endif
                    </div>
                </div>
            </div>

            {{-- GL Summary card --}}
            <div class="et-section-card">
                <div class="et-section-header">
                    <div class="et-section-icon" style="background:linear-gradient(135deg,#0f172a,#334155);">
                        <i class="fas fa-calculator"></i>
                    </div>
                    <h2>GL Summary</h2>
                </div>
                <div class="et-section-body">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="et-gl-mini-card">
                                <div class="et-gl-mini-label">GL Rule</div>
                                <div class="et-gl-mini-value">{{ $transaction->getGlDescription() }}</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="et-gl-mini-card" style="background:#fff7ed;border-color:#fed7aa;">
                                <div class="et-gl-mini-label">Sub-Ledger</div>
                                <div class="et-gl-mini-value">
                                    @if ($transaction->isOutflow())
                                        Debit (increase payable)
                                    @else
                                        Credit (reduce payable)
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="et-gl-mini-card" style="background:#eff6ff;border-color:#bfdbfe;">
                                <div class="et-gl-mini-label">Bank Book</div>
                                <div class="et-gl-mini-value">
                                    @if ($transaction->isBankMode() && $transaction->isOutflow())
                                        Decrease
                                    @else
                                        No change
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    @if ($transaction->journalEntry)
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-2" style="border-top:1px solid #e2e8f0;">
                            <span class="text-muted small">JE #</span>
                            <span class="badge bg-secondary-subtle text-secondary">{{ $transaction->journalEntry->entry_no }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Actions card --}}
            <div class="et-section-card">
                <div class="et-section-header">
                    <div class="et-section-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h2>Actions</h2>
                </div>
                <div class="et-section-body d-grid gap-2">
                    <a href="{{ route('admin.employee-transactions.slip', ['id' => $transaction->id]) }}" class="btn btn-outline-primary w-100" target="_blank">
                        <i class="fas fa-print me-1"></i> Print Slip
                    </a>

                    @if (! $transaction->is_reversed && $canReverse)
                        <button type="button" class="btn btn-outline-danger w-100" id="reverseBtn">
                            <i class="fas fa-rotate-left me-1"></i> Reverse Transaction
                        </button>
                        <div class="alert alert-warning small mb-0" style="border-radius:0.5rem;">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Reversing backs out the GL journal entry and employee ledger.
                            Bank balance will be restored (if bank mode). This action cannot be undone.
                        </div>
                    @else
                        <div class="alert alert-secondary small mb-0" style="border-radius:0.5rem;">
                            <i class="fas fa-ban me-1"></i>
                            @if ($transaction->is_reversed)
                                This transaction is already reversed. No further actions available.
                            @else
                                This transaction cannot be reversed.
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Boot config for EmployeeTransaction.js --}}
<script>
    window.ET_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ csrf_token() }}',
        transactionId: {{ $transaction->id }},
        transactionCode: '{{ $transaction->transaction_code }}',
        routes: {
            'index': '{{ route("admin.employee-transactions.index") }}',
            'show': '{{ route("admin.employee-transactions.show", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'reverse': '{{ route("admin.employee-transactions.reverse", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'slip': '{{ route("admin.employee-transactions.slip", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'employee-show': '{{ url("/admin/employees") }}/',
        },
    };
</script>

@push('scripts')
<script src="/assets/js/EmployeeTransaction.js?v={{ filemtime(public_path('assets/js/EmployeeTransaction.js')) }}"></script>
<script>
$(function () {
    // ====== Reverse transaction (SweetAlert2 prompt for reason — required) ======
    $('#reverseBtn').on('click', function (e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Reverse this transaction?',
            html: '<p class="text-muted small mb-2">This will reverse the GL entry and employee ledger. ' +
                  'Bank balance will be restored (if bank mode). This action cannot be undone. Please provide a reason:</p>' +
                  '<textarea id="swalReverseReason" class="form-control" rows="3" ' +
                  'placeholder="Reason for reversal" maxlength="500"></textarea>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-rotate-left"></i> Reverse Transaction',
            cancelButtonText: 'Keep Transaction',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
            focusConfirm: false,
            preConfirm: function () {
                var reason = $('#swalReverseReason').val();
                if (!reason || !reason.trim()) {
                    Swal.showValidationMessage('A reversal reason is required.');
                    return false;
                }
                return reason;
            }
        }).then(function (result) {
            if (result.isConfirmed && result.value) {
                // Submit via AJAX
                $.ajax({
                    url: '/admin/employee-transactions/' + window.ET_BOOT.transactionId + '/reverse',
                    method: 'POST',
                    data: {
                        _token: window.ET_BOOT.csrf_token,
                        reverse_reason: result.value
                    },
                    success: function (resp) {
                        if (resp.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Reversed!',
                                text: resp.message || 'Transaction reversed successfully.',
                                timer: 2000,
                                showConfirmButton: false
                            }).then(function () {
                                location.reload();
                            });
                        } else {
                            Swal.fire('Error', resp.message || 'Failed to reverse.', 'error');
                        }
                    },
                    error: function (xhr) {
                        var msg = xhr.responseJSON?.message || 'An error occurred.';
                        Swal.fire('Error', msg, 'error');
                    }
                });
            }
        });
    });
});
</script>
@endpush
@endsection