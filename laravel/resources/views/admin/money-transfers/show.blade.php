@extends('layouts.admin')

@section('content')
@php
    $typeBadge = function () use ($transfer): string {
        $badges = [
            'cash_to_bank' => '<span class="badge bg-primary fs-6"><i class="fas fa-university me-1"></i>Cash to Bank</span>',
            'bank_to_cash' => '<span class="badge bg-secondary fs-6"><i class="fas fa-money-bill me-1"></i>Bank to Cash</span>',
            'cash_to_cash' => '<span class="badge bg-success fs-6"><i class="fas fa-money-bill-transfer me-1"></i>Cash to Cash</span>',
            'bank_to_bank' => '<span class="badge bg-info fs-6"><i class="fas fa-exchange-alt me-1"></i>Bank to Bank</span>',
        ];
        return $badges[$transfer->transfer_type] ?? '<span class="badge bg-light text-dark fs-6">' . e($transfer->transfer_type) . '</span>';
    };

    $statusBadge = function (bool $large = false) use ($transfer): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        if ($transfer->is_reversed) {
            return '<span class="badge bg-danger' . $cls . '"><i class="fas fa-rotate-left me-1"></i>Reversed</span>';
        }
        return '<span class="badge bg-success' . $cls . '"><i class="fas fa-check me-1"></i>Active</span>';
    };

    $typeLabel = function (string $type): string {
        return [
            'cash_to_bank' => 'Cash to Bank',
            'bank_to_cash' => 'Bank to Cash',
            'cash_to_cash' => 'Cash to Cash',
            'bank_to_bank' => 'Bank to Bank',
        ][$type] ?? ucfirst(str_replace('_', ' ', $type));
    };

    $glInfo = function (string $type): string {
        return [
            'cash_to_bank' => 'Dr Bank / Cr Cash',
            'bank_to_cash' => 'Dr Cash / Cr Bank',
            'cash_to_cash' => 'No GL (same ledger)',
            'bank_to_bank' => 'Dr Dest Bank / Cr Source Bank',
        ][$type] ?? 'N/A';
    };

    $typeGradients = [
        'cash_to_bank' => 'linear-gradient(135deg,#0d9488,#059669)',
        'bank_to_cash' => 'linear-gradient(135deg,#6b7280,#4b5563)',
        'cash_to_cash' => 'linear-gradient(135deg,#059669,#047857)',
        'bank_to_bank' => 'linear-gradient(135deg,#3b82f6,#2563eb)',
    ];
    $heroGradient = $typeGradients[$transfer->transfer_type] ?? $typeGradients['cash_to_bank'];

    $typeIcons = [
        'cash_to_bank' => 'fa-university',
        'bank_to_cash' => 'fa-money-bill',
        'cash_to_cash' => 'fa-money-bill-transfer',
        'bank_to_bank' => 'fa-exchange-alt',
    ];
    $heroIcon = $typeIcons[$transfer->transfer_type] ?? $typeIcons['cash_to_bank'];

    $typeShadowColors = [
        'cash_to_bank' => 'rgba(13,148,136,0.18)',
        'bank_to_cash' => 'rgba(107,114,128,0.18)',
        'cash_to_cash' => 'rgba(5,150,105,0.18)',
        'bank_to_bank' => 'rgba(59,130,246,0.18)',
    ];
    $heroShadow = $typeShadowColors[$transfer->transfer_type] ?? $typeShadowColors['cash_to_bank'];

    $typeAmountShadow = [
        'cash_to_bank' => 'rgba(13,148,136,0.2)',
        'bank_to_cash' => 'rgba(107,114,128,0.2)',
        'cash_to_cash' => 'rgba(5,150,105,0.2)',
        'bank_to_bank' => 'rgba(59,130,246,0.2)',
    ];
    $amountShadow = $typeAmountShadow[$transfer->transfer_type] ?? $typeAmountShadow['cash_to_bank'];
@endphp

<style>
    .mt-show-page { --mt-primary: #0d9488; --mt-primary-dark: #059669; }

    /* Hero */
    .mt-show-hero {
        background: linear-gradient(135deg, var(--mt-primary), var(--mt-primary-dark));
        border-radius: 1rem;
        padding: 1.5rem 1.75rem;
        color: #fff;
        box-shadow: 0 8px 32px rgba(13,148,136,0.18);
        margin-bottom: 1.5rem;
    }
    .mt-show-hero h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: 0.15rem; }
    .mt-show-hero .mt-hero-subtitle { font-size: 0.82rem; opacity: 0.85; }
    .mt-show-hero .mt-hero-amount {
        font-size: 2rem; font-weight: 800; font-variant-numeric: tabular-nums;
        line-height: 1.1;
    }

    /* Section cards */
    .mt-section-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 0.875rem;
        box-shadow: 0 1px 4px rgba(15,23,42,0.04);
        margin-bottom: 1.25rem;
        overflow: hidden;
        transition: box-shadow 0.2s;
    }
    .mt-section-card:hover { box-shadow: 0 4px 16px rgba(15,23,42,0.07); }
    .mt-section-header {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.75rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .mt-section-header h2 { font-size: 0.88rem; font-weight: 700; margin: 0; color: #0f172a; }
    .mt-section-header .mt-section-icon {
        width: 32px; height: 32px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.85rem; color: #fff;
    }
    .mt-section-body { padding: 1.25rem; }

    /* Detail grid items */
    .mt-detail-item {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 0.75rem 1rem;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .mt-detail-item:hover { border-color: #cbd5e1; box-shadow: 0 2px 8px rgba(15,23,42,0.04); }
    .mt-detail-label {
        font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em;
        color: #64748b; margin-bottom: 0.2rem; font-weight: 600;
    }
    .mt-detail-value { font-weight: 600; color: #0f172a; font-size: 0.92rem; }

    /* Detail grid */
    .mt-detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 1rem;
    }

    /* Amount highlight card */
    .mt-amount-card {
        background: linear-gradient(135deg, var(--mt-primary), var(--mt-primary-dark));
        border-radius: 0.875rem;
        padding: 1.5rem;
        color: #fff;
        text-align: center;
        box-shadow: 0 8px 24px rgba(13,148,136,0.2);
    }
    .mt-amount-card .mt-amount-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.85; }
    .mt-amount-card .mt-amount-value { font-size: 2rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .mt-amount-card .mt-amount-meta { font-size: 0.78rem; opacity: 0.8; margin-top: 0.25rem; }

    /* Reversal banner */
    .mt-reversal-banner {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-left: 4px solid #dc2626;
        border-radius: 0.875rem;
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
    }

    /* GL table */
    .mt-gl-table th {
        font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em;
        color: #64748b; font-weight: 600;
    }
    .mt-gl-table td { font-size: 0.88rem; }
    .mt-gl-table .debit-col { color: #0d9488; font-weight: 600; }
    .mt-gl-table .credit-col { color: #dc2626; font-weight: 600; }

    /* Action button bar */
    .mt-action-bar {
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
    .mt-gl-mini-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 0.75rem;
        text-align: center;
    }
    .mt-gl-mini-card .mt-gl-mini-label { font-size: 0.7rem; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em; }
    .mt-gl-mini-card .mt-gl-mini-value { font-weight: 700; font-size: 0.82rem; color: #0f172a; }

    /* Responsive */
    @media (max-width: 768px) {
        .mt-show-hero { padding: 1rem 1.15rem; border-radius: 0.75rem; }
        .mt-show-hero h1 { font-size: 1.1rem; }
        .mt-show-hero .mt-hero-amount { font-size: 1.5rem; }
        .mt-section-body { padding: 0.85rem; }
        .mt-amount-card .mt-amount-value { font-size: 1.5rem; }
        .mt-action-bar { position: static; border-radius: 0.75rem; }
    }
</style>

<div class="container-fluid py-3 mt-show-page">
    {{-- Hero header --}}
    <header class="mt-show-hero" id="mtShowHero" style="background:{{ $heroGradient }};box-shadow:0 8px 32px {{ $heroShadow }};">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1>
                    <i class="fas {{ $heroIcon }} me-2"></i>Money Transfer {{ $transfer->transfer_code }}
                </h1>
                <p class="mt-hero-subtitle mb-2">
                    @if ($transfer->fromBranch)<i class="fas fa-code-branch me-1"></i> {{ $transfer->fromBranch->branch_name }}@endif
                    &rarr;
                    @if ($transfer->toBranch){{ $transfer->toBranch->branch_name }}@endif
                    &middot; <i class="fas fa-calendar me-1"></i> {{ \Carbon\Carbon::parse($transfer->transfer_date)->format('d M Y') }}
                </p>
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    {!! $statusBadge() !!}
                    {!! $typeBadge() !!}
                </div>
            </div>
            <div class="text-end">
                <div class="mt-hero-amount">Tk {{ number_format((float) $transfer->amount, 2) }}</div>
                <div class="mt-hero-subtitle">{{ $typeLabel($transfer->transfer_type) }}</div>
                <div class="d-flex gap-2 mt-2 justify-content-end">
                    <a href="{{ route('admin.money-transfers.slip', ['id' => $transfer->id]) }}" class="btn btn-outline-light btn-sm" target="_blank">
                        <i class="fas fa-print me-1"></i> Print
                    </a>
                    <a href="{{ route('admin.money-transfers.index') }}" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </header>

    {{-- Reversal alert --}}
    @if ($transfer->is_reversed)
        <div class="mt-reversal-banner">
            <div class="d-flex align-items-start gap-2">
                <i class="fas fa-rotate-left fa-lg text-danger mt-1"></i>
                <div class="flex-grow-1">
                    <strong class="text-danger">This transfer has been reversed.</strong>
                    <div class="mt-1 small">
                        @if ($transfer->reversed_at)
                            <span class="me-3"><i class="fas fa-calendar me-1"></i>
                                Reversed at: {{ \Carbon\Carbon::parse($transfer->reversed_at)->format('d M Y H:i') }}
                            </span>
                        @endif
                        @if ($transfer->reversed_by)
                            <span class="me-3"><i class="fas fa-user me-1"></i>
                                By: User #{{ $transfer->reversed_by }}
                            </span>
                        @endif
                    </div>
                    @if (!empty($transfer->reverse_reason))
                        <div class="mt-1">
                            <span class="text-muted">Reason:</span>
                            <em>{{ $transfer->reverse_reason }}</em>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- Left: main details --}}
        <div class="col-lg-8">
            {{-- Transfer details section card --}}
            <div class="mt-section-card">
                <div class="mt-section-header">
                    <div class="mt-section-icon" style="background:linear-gradient(135deg,#0d9488,#059669);">
                        <i class="fas fa-circle-info"></i>
                    </div>
                    <h2>Transfer Details</h2>
                </div>
                <div class="mt-section-body">
                    <div class="mt-detail-grid">
                        <div class="mt-detail-item">
                            <div class="mt-detail-label">Transfer Code</div>
                            <div class="mt-detail-value">
                                <span class="badge bg-secondary-subtle text-secondary" style="font-size:0.85rem;">{{ $transfer->transfer_code }}</span>
                            </div>
                        </div>
                        <div class="mt-detail-item">
                            <div class="mt-detail-label">Transfer Type</div>
                            <div class="mt-detail-value">
                                {!! $typeBadge() !!}
                                <div class="small text-muted mt-1">{{ $glInfo($transfer->transfer_type) }}</div>
                            </div>
                        </div>
                        <div class="mt-detail-item">
                            <div class="mt-detail-label">Transfer Date</div>
                            <div class="mt-detail-value">
                                <i class="fas fa-calendar me-1 text-muted"></i>
                                {{ \Carbon\Carbon::parse($transfer->transfer_date)->format('d M Y') }}
                            </div>
                        </div>
                        <div class="mt-detail-item">
                            <div class="mt-detail-label">From Branch</div>
                            <div class="mt-detail-value">
                                @if ($transfer->fromBranch)
                                    <i class="fas fa-code-branch me-1 text-muted"></i>
                                    {{ $transfer->fromBranch->branch_name }}
                                    <span class="text-muted small">({{ $transfer->fromBranch->branch_code }})</span>
                                @else
                                    <span class="text-muted">--</span>
                                @endif
                            </div>
                        </div>
                        <div class="mt-detail-item">
                            <div class="mt-detail-label">To Branch</div>
                            <div class="mt-detail-value">
                                @if ($transfer->toBranch)
                                    <i class="fas fa-code-branch me-1 text-muted"></i>
                                    {{ $transfer->toBranch->branch_name }}
                                    <span class="text-muted small">({{ $transfer->toBranch->branch_code }})</span>
                                @else
                                    <span class="text-muted">--</span>
                                @endif
                            </div>
                        </div>
                        @if (in_array($transfer->transfer_type, ['bank_to_cash', 'bank_to_bank']))
                            <div class="mt-detail-item">
                                <div class="mt-detail-label">From Bank</div>
                                <div class="mt-detail-value">
                                    @if ($transfer->fromBank)
                                        <i class="fas fa-university me-1 text-muted"></i>
                                        {{ $transfer->fromBank->bank_name }}
                                        @if (!empty($transfer->fromBank->bank_code))
                                            <span class="text-muted small">({{ $transfer->fromBank->bank_code }})</span>
                                        @endif
                                        @if (!empty($transfer->fromBank->account_no))
                                            <div class="small text-muted mt-1"><i class="fas fa-hashtag me-1"></i>A/C: {{ $transfer->fromBank->account_no }}</div>
                                        @endif
                                    @else
                                        <span class="text-muted">--</span>
                                    @endif
                                </div>
                            </div>
                        @endif
                        @if (in_array($transfer->transfer_type, ['cash_to_bank', 'bank_to_bank']))
                            <div class="mt-detail-item">
                                <div class="mt-detail-label">To Bank</div>
                                <div class="mt-detail-value">
                                    @if ($transfer->toBank)
                                        <i class="fas fa-university me-1 text-muted"></i>
                                        {{ $transfer->toBank->bank_name }}
                                        @if (!empty($transfer->toBank->bank_code))
                                            <span class="text-muted small">({{ $transfer->toBank->bank_code }})</span>
                                        @endif
                                        @if (!empty($transfer->toBank->account_no))
                                            <div class="small text-muted mt-1"><i class="fas fa-hashtag me-1"></i>A/C: {{ $transfer->toBank->account_no }}</div>
                                        @endif
                                    @else
                                        <span class="text-muted">--</span>
                                    @endif
                                </div>
                            </div>
                        @endif
                        <div class="mt-detail-item">
                            <div class="mt-detail-label">Amount</div>
                            <div class="mt-detail-value">
                                <span class="text-success">Tk {{ number_format((float) $transfer->amount, 2) }}</span>
                            </div>
                        </div>
                        <div class="mt-detail-item">
                            <div class="mt-detail-label">Notes</div>
                            <div class="mt-detail-value">{!! nl2br(e($transfer->notes ?: '--')) !!}</div>
                        </div>
                        <div class="mt-detail-item">
                            <div class="mt-detail-label">Created By</div>
                            <div class="mt-detail-value small text-muted">
                                @if ($transfer->created_by) User #{{ $transfer->created_by }} @else -- @endif
                                @if ($transfer->created_at) &middot; {{ $transfer->created_at->format('d M Y H:i') }} @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- GL Journal Entry card --}}
            @if ($transfer->journalEntry)
                @php
                    $je           = $transfer->journalEntry;
                    $jeTotalDr    = 0;
                    $jeTotalCr    = 0;
                    foreach ($je->lines as $line) {
                        $jeTotalDr += (float) $line->debit;
                        $jeTotalCr += (float) $line->credit;
                    }
                @endphp
                <div class="mt-section-card">
                    <div class="mt-section-header">
                        <div class="mt-section-icon" style="background:linear-gradient(135deg,#0f172a,#334155);">
                            <i class="fas fa-book"></i>
                        </div>
                        <h2>GL Journal Entry</h2>
                        @if ($je->is_reversed)
                            <span class="badge bg-danger-subtle text-danger ms-auto">
                                <i class="fas fa-rotate-left me-1"></i>Reversed
                            </span>
                        @endif
                    </div>
                    <div class="mt-section-body">
                        <div class="mt-detail-grid mb-3">
                            <div class="mt-detail-item">
                                <div class="mt-detail-label">JE #</div>
                                <div class="mt-detail-value">
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $je->entry_no }}</span>
                                </div>
                            </div>
                            <div class="mt-detail-item">
                                <div class="mt-detail-label">Date</div>
                                <div class="mt-detail-value">
                                    {{ \Carbon\Carbon::parse($je->entry_date)->format('d M Y') }}
                                </div>
                            </div>
                            <div class="mt-detail-item">
                                <div class="mt-detail-label">Description</div>
                                <div class="mt-detail-value">{{ $je->description ?: '--' }}</div>
                            </div>
                            @if (!empty($je->source))
                                <div class="mt-detail-item">
                                    <div class="mt-detail-label">Source</div>
                                    <div class="mt-detail-value">{{ $je->source }}</div>
                                </div>
                            @endif
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0 mt-gl-table">
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
                                                    <span class="text-muted">--</span>
                                                @endif
                                            </td>
                                            <td class="text-end credit-col">
                                                @if ((float) $line->credit > 0)
                                                    {{ number_format((float) $line->credit, 2) }}
                                                @else
                                                    <span class="text-muted">--</span>
                                                @endif
                                            </td>
                                            <td class="small text-muted">{{ $line->memo ?: '--' }}</td>
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
            @if ($transfer->intercompanyJournalEntry)
                @php
                    $icje        = $transfer->intercompanyJournalEntry;
                    $icTotalDr   = 0;
                    $icTotalCr   = 0;
                    foreach ($icje->lines as $line) {
                        $icTotalDr += (float) $line->debit;
                        $icTotalCr += (float) $line->credit;
                    }
                @endphp
                <div class="mt-section-card">
                    <div class="mt-section-header">
                        <div class="mt-section-icon" style="background:linear-gradient(135deg,#0ea5e9,#0284c7);">
                            <i class="fas fa-right-left"></i>
                        </div>
                        <h2>Intercompany GL Journal</h2>
                        @if ($icje->is_reversed)
                            <span class="badge bg-danger-subtle text-danger ms-auto">
                                <i class="fas fa-rotate-left me-1"></i>Reversed
                            </span>
                        @endif
                    </div>
                    <div class="mt-section-body">
                        <div class="mt-detail-grid mb-3">
                            <div class="mt-detail-item">
                                <div class="mt-detail-label">JE #</div>
                                <div class="mt-detail-value">
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $icje->entry_no }}</span>
                                </div>
                            </div>
                            <div class="mt-detail-item">
                                <div class="mt-detail-label">Date</div>
                                <div class="mt-detail-value">
                                    {{ \Carbon\Carbon::parse($icje->entry_date)->format('d M Y') }}
                                </div>
                            </div>
                            <div class="mt-detail-item">
                                <div class="mt-detail-label">Description</div>
                                <div class="mt-detail-value">{{ $icje->description ?: '--' }}</div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0 mt-gl-table">
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
                                                    <span class="text-muted">--</span>
                                                @endif
                                            </td>
                                            <td class="text-end credit-col">
                                                @if ((float) $line->credit > 0)
                                                    {{ number_format((float) $line->credit, 2) }}
                                                @else
                                                    <span class="text-muted">--</span>
                                                @endif
                                            </td>
                                            <td class="small text-muted">{{ $line->memo ?: '--' }}</td>
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

            {{-- Cash Ledger Entries card --}}
            @if (isset($cashLedgerEntries) && $cashLedgerEntries->isNotEmpty())
                <div class="mt-section-card">
                    <div class="mt-section-header">
                        <div class="mt-section-icon" style="background:linear-gradient(135deg,#059669,#047857);">
                            <i class="fas fa-money-bill"></i>
                        </div>
                        <h2>Cash Ledger Entries</h2>
                        <span class="badge bg-success-subtle text-success ms-auto">{{ $cashLedgerEntries->count() }}</span>
                    </div>
                    <div class="mt-section-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0 mt-gl-table">
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
                                    @foreach ($cashLedgerEntries as $cle)
                                        <tr>
                                            <td class="small text-nowrap">
                                                @if (!empty($cle->transaction_date))
                                                    {{ \Carbon\Carbon::parse($cle->transaction_date)->format('d M Y') }}
                                                @else
                                                    --
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">{{ $cle->transaction_type ?? '--' }}</span>
                                            </td>
                                            <td class="text-end debit-col">
                                                @if (!empty($cle->debit) && (float) $cle->debit > 0)
                                                    {{ number_format((float) $cle->debit, 2) }}
                                                @else
                                                    <span class="text-muted">--</span>
                                                @endif
                                            </td>
                                            <td class="text-end credit-col">
                                                @if (!empty($cle->credit) && (float) $cle->credit > 0)
                                                    {{ number_format((float) $cle->credit, 2) }}
                                                @else
                                                    <span class="text-muted">--</span>
                                                @endif
                                            </td>
                                            <td class="text-end fw-semibold running-bal">
                                                @if (!empty($cle->running_balance))
                                                    {{ number_format((float) $cle->running_balance, 2) }}
                                                @elseif (!empty($cle->balance))
                                                    {{ number_format((float) $cle->balance, 2) }}
                                                @else
                                                    --
                                                @endif
                                            </td>
                                            <td class="small text-muted">{{ $cle->description ?: '--' }}</td>
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
            <div class="mt-amount-card mb-3" style="background:{{ $heroGradient }};box-shadow:0 8px 24px {{ $amountShadow }};">
                <div class="mt-amount-label">Transfer Amount</div>
                <div class="mt-amount-value">Tk {{ number_format((float) $transfer->amount, 2) }}</div>
                <div class="mt-amount-meta">
                    <i class="fas fa-calendar me-1"></i>
                    {{ \Carbon\Carbon::parse($transfer->transfer_date)->format('d M Y') }}
                </div>
            </div>

            {{-- Status card --}}
            <div class="mt-section-card">
                <div class="mt-section-header">
                    <div class="mt-section-icon" style="background:linear-gradient(135deg,{{ $transfer->is_reversed ? '#dc2626,#b91c1c' : '#059669,#047857' }});">
                        <i class="fas fa-flag"></i>
                    </div>
                    <h2>Status</h2>
                </div>
                <div class="mt-section-body text-center">
                    <div class="mb-2">{!! $statusBadge(true) !!}</div>
                    <div class="small text-muted">
                        @if ($transfer->is_reversed)
                            Reversed -- GL and cash/bank ledgers have been backed out.
                        @else
                            Active -- GL posted, cash/bank ledgers updated.
                        @endif
                    </div>
                </div>
            </div>

            {{-- GL Summary card --}}
            <div class="mt-section-card">
                <div class="mt-section-header">
                    <div class="mt-section-icon" style="background:linear-gradient(135deg,#0f172a,#334155);">
                        <i class="fas fa-calculator"></i>
                    </div>
                    <h2>GL Summary</h2>
                </div>
                <div class="mt-section-body">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="mt-gl-mini-card">
                                <div class="mt-gl-mini-label">GL Rule</div>
                                <div class="mt-gl-mini-value">{{ $glInfo($transfer->transfer_type) }}</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mt-gl-mini-card" style="background:#fff7ed;border-color:#fed7aa;">
                                <div class="mt-gl-mini-label">Cash Ledger</div>
                                <div class="mt-gl-mini-value">
                                    @if (in_array($transfer->transfer_type, ['cash_to_bank', 'cash_to_cash']))
                                        Credit (reduce)
                                    @elseif (in_array($transfer->transfer_type, ['bank_to_cash']))
                                        Debit (increase)
                                    @else
                                        No change
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mt-gl-mini-card" style="background:#eff6ff;border-color:#bfdbfe;">
                                <div class="mt-gl-mini-label">Bank Book</div>
                                <div class="mt-gl-mini-value">
                                    @if (in_array($transfer->transfer_type, ['cash_to_bank', 'bank_to_bank']))
                                        Debit (increase)
                                    @elseif (in_array($transfer->transfer_type, ['bank_to_cash']))
                                        Credit (decrease)
                                    @else
                                        No change
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    @if ($transfer->journalEntry)
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-2" style="border-top:1px solid #e2e8f0;">
                            <span class="text-muted small">JE #</span>
                            <span class="badge bg-secondary-subtle text-secondary">{{ $transfer->journalEntry->entry_no }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Actions card --}}
            <div class="mt-section-card">
                <div class="mt-section-header">
                    <div class="mt-section-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h2>Actions</h2>
                </div>
                <div class="mt-section-body d-grid gap-2">
                    <a href="{{ route('admin.money-transfers.slip', ['id' => $transfer->id]) }}" class="btn btn-outline-primary w-100" target="_blank">
                        <i class="fas fa-print me-1"></i> Print Slip
                    </a>

                    @if (! $transfer->is_reversed)
                        <button type="button" class="btn btn-outline-danger w-100" id="reverseBtn">
                            <i class="fas fa-rotate-left me-1"></i> Reverse Transfer
                        </button>
                        <div class="alert alert-warning small mb-0" style="border-radius:0.5rem;">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Reversing backs out the GL journal entry, cash/bank ledgers, and bank book balance. This action cannot be undone.
                        </div>
                    @else
                        <div class="alert alert-secondary small mb-0" style="border-radius:0.5rem;">
                            <i class="fas fa-ban me-1"></i>
                            This transfer is already reversed. No further actions available.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Boot config for MoneyTransfer JS --}}
<script>
    window.MT_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ csrf_token() }}',
        transferId: {{ $transfer->id }},
        transferCode: '{{ $transfer->transfer_code }}',
        routes: {
            'index': '{{ route("admin.money-transfers.index") }}',
            'show': '{{ route("admin.money-transfers.show", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'reverse': '{{ route("admin.money-transfers.reverse", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'slip': '{{ route("admin.money-transfers.slip", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
        },
    };
</script>

@push('scripts')
<script>
$(function () {
    // ====== Reverse transfer (SweetAlert2 prompt for reason -- required) ======
    $('#reverseBtn').on('click', function (e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Reverse this transfer?',
            html: '<p class="text-muted small mb-2">This will reverse the GL entry, cash/bank ledgers, and bank book balance. This action cannot be undone. Please provide a reason:</p>' +
                  '<textarea id="swalReverseReason" class="form-control" rows="3" ' +
                  'placeholder="Reason for reversal" maxlength="500"></textarea>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-rotate-left"></i> Reverse Transfer',
            cancelButtonText: 'Keep Transfer',
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
                    url: '/admin/money-transfers/' + window.MT_BOOT.transferId + '/reverse',
                    method: 'POST',
                    data: {
                        _token: window.MT_BOOT.csrf_token,
                        reverse_reason: result.value
                    },
                    success: function (resp) {
                        if (resp.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Reversed!',
                                text: resp.message || 'Transfer reversed successfully.',
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
                        var msg;
                        if (xhr.status === 422 && xhr.responseJSON) {
                            if (xhr.responseJSON.message) {
                                msg = xhr.responseJSON.message;
                            } else if (xhr.responseJSON.errors) {
                                var firstKey = Object.keys(xhr.responseJSON.errors)[0];
                                msg = xhr.responseJSON.errors[firstKey][0];
                            } else {
                                msg = 'Validation error.';
                            }
                        } else {
                            msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'An error occurred.';
                        }
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
