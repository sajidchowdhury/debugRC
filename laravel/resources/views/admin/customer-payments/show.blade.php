@extends('layouts.admin')

@section('content')
@php
    $modeBadge = function (string $mode) use ($payment): string {
        $cls = ' fs-6';
        return [
            'cash'            => '<span class="badge bg-secondary' . $cls . '"><i class="fas fa-money-bill me-1"></i>Cash</span>',
            'bank'            => '<span class="badge bg-primary' . $cls . '"><i class="fas fa-university me-1"></i>Bank</span>',
            'mobile_banking' => '<span class="badge bg-info' . $cls . '"><i class="fas fa-mobile-screen me-1"></i>Mobile</span>',
            'cheque'          => '<span class="badge bg-warning text-dark' . $cls . '"><i class="fas fa-money-check me-1"></i>Cheque</span>',
            'adjustment'      => '<span class="badge bg-dark' . $cls . '"><i class="fas fa-sliders me-1"></i>Adjustment</span>',
        ][$mode] ?? '<span class="badge bg-light text-dark' . $cls . '">' . e($mode) . '</span>';
    };

    $statusBadge = function (bool $large = false) use ($payment): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        if ($payment->is_reversed) {
            return '<span class="badge bg-danger' . $cls . '"><i class="fas fa-rotate-left me-1"></i>Reversed</span>';
        }
        return '<span class="badge bg-success' . $cls . '"><i class="fas fa-check me-1"></i>Active</span>';
    };

    $typeBadge = function () use ($payment): string {
        $type = $payment->transaction_type ?? 'receive';
        $badges = [
            'receive'   => '<span class="badge bg-success fs-6"><i class="fas fa-hand-holding-dollar me-1"></i>Payment Received</span>',
            'discount'  => '<span class="badge fs-6" style="background:#7c3aed;color:#fff;"><i class="fas fa-tags me-1"></i>Discount</span>',
            'write_off' => '<span class="badge bg-danger fs-6"><i class="fas fa-file-circle-xmark me-1"></i>Write-off</span>',
            'payment'   => '<span class="badge bg-warning text-dark fs-6"><i class="fas fa-rotate-left me-1"></i>Refund</span>',
        ];
        return $badges[$type] ?? '<span class="badge bg-light text-dark fs-6">' . e($type) . '</span>';
    };

    $typeGradients = [
        'receive'   => 'linear-gradient(135deg,#059669,#0d9488)',
        'discount'  => 'linear-gradient(135deg,#7c3aed,#6d28d9)',
        'write_off' => 'linear-gradient(135deg,#dc2626,#b91c1c)',
        'payment'   => 'linear-gradient(135deg,#f59e0b,#d97706)',
    ];
    $heroGradient = $typeGradients[$payment->transaction_type ?? 'receive'] ?? $typeGradients['receive'];

    $typeIcons = [
        'receive'   => 'fa-hand-holding-dollar',
        'discount'  => 'fa-tags',
        'write_off' => 'fa-file-circle-xmark',
        'payment'   => 'fa-rotate-left',
    ];
    $heroIcon = $typeIcons[$payment->transaction_type ?? 'receive'] ?? $typeIcons['receive'];

    $isArReduction = $payment->isArReduction();
@endphp

<style>
    .st-show-page { --st-primary: #059669; --st-primary-dark: #0d9488; --st-accent: #7c3aed; }

    /* Hero */
    .st-show-hero {
        background: linear-gradient(135deg, var(--st-primary), var(--st-primary-dark));
        border-radius: 1rem;
        padding: 1.5rem 1.75rem;
        color: #fff;
        box-shadow: 0 8px 32px rgba(5,150,105,0.18);
        margin-bottom: 1.5rem;
    }
    .st-show-hero.discount-hero {
        background: linear-gradient(135deg, #7c3aed, #6d28d9);
        box-shadow: 0 8px 32px rgba(124,58,237,0.18);
    }
    .st-show-hero.writeoff-hero {
        background: linear-gradient(135deg, #dc2626, #b91c1c);
        box-shadow: 0 8px 32px rgba(220,38,38,0.18);
    }
    .st-show-hero.refund-hero {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        box-shadow: 0 8px 32px rgba(245,158,11,0.18);
    }
    .st-show-hero h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: 0.15rem; }
    .st-show-hero .st-hero-subtitle { font-size: 0.82rem; opacity: 0.85; }
    .st-show-hero .st-hero-amount {
        font-size: 2rem; font-weight: 800; font-variant-numeric: tabular-nums;
        line-height: 1.1;
    }

    /* Section cards */
    .st-section-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 0.875rem;
        box-shadow: 0 1px 4px rgba(15,23,42,0.04);
        margin-bottom: 1.25rem;
        overflow: hidden;
        transition: box-shadow 0.2s;
    }
    .st-section-card:hover { box-shadow: 0 4px 16px rgba(15,23,42,0.07); }
    .st-section-header {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.75rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .st-section-header h2 { font-size: 0.88rem; font-weight: 700; margin: 0; color: #0f172a; }
    .st-section-header .st-section-icon {
        width: 32px; height: 32px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.85rem; color: #fff;
    }
    .st-section-body { padding: 1.25rem; }

    /* Detail grid */
    .st-detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 0.75rem;
    }

    /* Detail grid items */
    .st-detail-item {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 0.75rem 1rem;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .st-detail-item:hover { border-color: #cbd5e1; box-shadow: 0 2px 8px rgba(15,23,42,0.04); }
    .st-detail-label {
        font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em;
        color: #64748b; margin-bottom: 0.2rem; font-weight: 600;
    }
    .st-detail-value { font-weight: 600; color: #0f172a; font-size: 0.92rem; }

    /* Amount highlight card */
    .st-amount-card {
        background: linear-gradient(135deg, var(--st-primary), var(--st-primary-dark));
        border-radius: 0.875rem;
        padding: 1.5rem;
        color: #fff;
        text-align: center;
        box-shadow: 0 8px 24px rgba(5,150,105,0.2);
    }
    .st-amount-card.discount-card {
        background: linear-gradient(135deg, #7c3aed, #6d28d9);
        box-shadow: 0 8px 24px rgba(124,58,237,0.2);
    }
    .st-amount-card.writeoff-card {
        background: linear-gradient(135deg, #dc2626, #b91c1c);
        box-shadow: 0 8px 24px rgba(220,38,38,0.2);
    }
    .st-amount-card.refund-card {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        box-shadow: 0 8px 24px rgba(245,158,11,0.2);
    }
    .st-amount-card .st-amount-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.85; }
    .st-amount-card .st-amount-value { font-size: 2rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .st-amount-card .st-amount-meta { font-size: 0.78rem; opacity: 0.8; margin-top: 0.25rem; }

    /* Due card */
    .st-due-card {
        border-radius: 0.875rem;
        padding: 1rem;
        text-align: center;
        border: 1px solid #fde68a;
        background: linear-gradient(135deg, #fffbeb, #fff7ed);
    }
    .st-due-card.zero-due {
        border-color: #bbf7d0;
        background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
    }
    .st-due-card .st-due-amount {
        font-size: 1.5rem; font-weight: 800; font-variant-numeric: tabular-nums;
    }
    .st-due-card .st-due-label { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }

    /* Reversal banner */
    .st-reversal-banner {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-left: 4px solid #dc2626;
        border-radius: 0.875rem;
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
    }

    /* GL table */
    .st-gl-table th {
        font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em;
        color: #64748b; font-weight: 600;
    }
    .st-gl-table td { font-size: 0.88rem; }
    .st-gl-table .debit-col { color: #0d9488; font-weight: 600; }
    .st-gl-table .credit-col { color: #dc2626; font-weight: 600; }

    /* Action button bar */
    .st-action-bar {
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
    .st-gl-mini-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 0.75rem;
        text-align: center;
    }
    .st-gl-mini-card .st-gl-mini-label { font-size: 0.7rem; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em; }
    .st-gl-mini-card .st-gl-mini-value { font-weight: 700; font-size: 0.82rem; color: #0f172a; }

    /* Responsive */
    @media (max-width: 768px) {
        .st-show-hero { padding: 1rem 1.15rem; border-radius: 0.75rem; }
        .st-show-hero h1 { font-size: 1.1rem; }
        .st-show-hero .st-hero-amount { font-size: 1.5rem; }
        .st-section-body { padding: 0.85rem; }
        .st-amount-card .st-amount-value { font-size: 1.5rem; }
        .st-action-bar { position: static; border-radius: 0.75rem; }
    }
</style>

<div class="container-fluid py-3 st-show-page">
    {{-- Hero header --}}
    <header class="st-show-hero {{ $payment->transaction_type === 'discount' ? 'discount-hero' : '' }}{{ $payment->transaction_type === 'write_off' ? 'writeoff-hero' : '' }}{{ $payment->transaction_type === 'payment' ? 'refund-hero' : '' }}" id="stShowHero">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1>
                    <i class="fas {{ $heroIcon }} me-2"></i>{{ $payment->getTransactionTypeLabel() }} {{ $payment->payment_code }}
                </h1>
                <p class="st-hero-subtitle mb-2">
                    @if ($payment->customer)<i class="fas fa-user-tie me-1"></i> {{ $payment->customer->customer_name }}@endif
                    @if ($payment->branch) · <i class="fas fa-code-branch me-1"></i> {{ $payment->branch->branch_name }}@endif
                    · <i class="fas fa-calendar me-1"></i> {{ \Carbon\Carbon::parse($payment->payment_date)->format('d M Y') }}
                </p>
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    {!! $statusBadge() !!}
                    {!! $typeBadge() !!}
                </div>
            </div>
            <div class="text-end">
                <div class="st-hero-amount">Tk {{ number_format((float) $payment->amount, 2) }}</div>
                <div class="st-hero-subtitle">
                    @if ($isArReduction)
                        Inflow — reduces receivable
                    @else
                        Receivable increase
                    @endif
                </div>
                <div class="d-flex gap-2 mt-2 justify-content-end">
                    <a href="{{ route('admin.customer-payments.print-receipt', $payment->id) }}" class="btn btn-outline-light btn-sm" target="_blank">
                        <i class="fas fa-print me-1"></i> Receipt
                    </a>
                    <a href="{{ route('admin.customer-payments.slip', $payment) }}" class="btn btn-outline-light btn-sm" target="_blank">
                        <i class="fas fa-receipt me-1"></i> Slip
                    </a>
                    <a href="{{ route('admin.customer-payments.index') }}" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </header>

    {{-- Reversal alert --}}
    @if ($payment->is_reversed)
        <div class="st-reversal-banner">
            <div class="d-flex align-items-start gap-2">
                <i class="fas fa-rotate-left fa-lg text-danger mt-1"></i>
                <div class="flex-grow-1">
                    <strong class="text-danger">This payment has been reversed.</strong>
                    <div class="mt-1 small">
                        @if ($payment->reversed_at)
                            <span class="me-3"><i class="fas fa-calendar me-1"></i>
                                Reversed at: {{ \Carbon\Carbon::parse($payment->reversed_at)->format('d M Y H:i') }}
                            </span>
                        @endif
                        @if ($payment->reversed_by)
                            <span class="me-3"><i class="fas fa-user me-1"></i>
                                By: User #{{ $payment->reversed_by }}
                            </span>
                        @endif
                    </div>
                    @if (!empty($payment->reverse_reason))
                        <div class="mt-1">
                            <span class="text-muted">Reason:</span>
                            <em>{{ $payment->reverse_reason }}</em>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- Left: main details --}}
        <div class="col-lg-8">
            {{-- Payment details section card --}}
            <div class="st-section-card">
                <div class="st-section-header">
                    <div class="st-section-icon" style="background:linear-gradient(135deg,#059669,#0d9488);">
                        <i class="fas fa-circle-info"></i>
                    </div>
                    <h2>Payment Details</h2>
                </div>
                <div class="st-section-body">
                    <div class="st-detail-grid">
                        <div class="st-detail-item">
                            <div class="st-detail-label">Payment Code</div>
                            <div class="st-detail-value">
                                <span class="badge bg-secondary-subtle text-secondary" style="font-size:0.85rem;">{{ $payment->payment_code }}</span>
                            </div>
                        </div>
                        <div class="st-detail-item">
                            <div class="st-detail-label">Transaction Type</div>
                            <div class="st-detail-value">
                                {!! $typeBadge() !!}
                                <div class="small text-muted mt-1">{{ $payment->getGlDescription() }}</div>
                            </div>
                        </div>
                        <div class="st-detail-item">
                            <div class="st-detail-label">Payment Date</div>
                            <div class="st-detail-value">
                                <i class="fas fa-calendar me-1 text-muted"></i>
                                {{ \Carbon\Carbon::parse($payment->payment_date)->format('d M Y') }}
                            </div>
                        </div>
                        <div class="st-detail-item">
                            <div class="st-detail-label">Customer</div>
                            <div class="st-detail-value">
                                @if ($payment->customer)
                                    <i class="fas fa-user-tie me-1 text-muted"></i>
                                    {{ $payment->customer->customer_name }}
                                    <span class="text-muted small">({{ $payment->customer->customer_code }})</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </div>
                        </div>
                        <div class="st-detail-item">
                            <div class="st-detail-label">Branch</div>
                            <div class="st-detail-value">
                                @if ($payment->branch)
                                    <i class="fas fa-code-branch me-1 text-muted"></i>
                                    {{ $payment->branch->branch_name }}
                                    <span class="text-muted small">({{ $payment->branch->branch_code }})</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </div>
                        </div>
                        <div class="st-detail-item">
                            <div class="st-detail-label">Payment Mode</div>
                            <div class="st-detail-value">{!! $modeBadge($payment->payment_mode) !!}</div>
                        </div>
                        @if ($payment->isBankMode() || $payment->bank)
                            <div class="st-detail-item">
                                <div class="st-detail-label">Bank</div>
                                <div class="st-detail-value">
                                    @if ($payment->bank)
                                        <i class="fas fa-university me-1 text-muted"></i>
                                        {{ $payment->bank->bank_name }}
                                        @if (!empty($payment->bank->account_number))
                                            <div class="small text-muted mt-1"><i class="fas fa-hashtag me-1"></i>A/C: {{ $payment->bank->account_number }}</div>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </div>
                            </div>
                        @endif
                        @if ($payment->collectedBy)
                            <div class="st-detail-item">
                                <div class="st-detail-label">Collected By</div>
                                <div class="st-detail-value">
                                    <i class="fas fa-user me-1 text-muted"></i>
                                    {{ $payment->collectedBy->name ?? $payment->collectedBy->employee_code ?? 'Employee #' . $payment->collected_by }}
                                </div>
                            </div>
                        @endif
                        <div class="st-detail-item">
                            <div class="st-detail-label">Amount</div>
                            <div class="st-detail-value">
                                @if ($isArReduction)
                                    <span class="text-success">Tk {{ number_format((float) $payment->amount, 2) }}</span>
                                    <span class="text-muted small ms-1">(inflow)</span>
                                @else
                                    <span class="text-danger">Tk {{ number_format((float) $payment->amount, 2) }}</span>
                                    <span class="text-muted small ms-1">(receivable increase)</span>
                                @endif
                            </div>
                        </div>
                        @if ((float) $payment->discount_amount > 0)
                            <div class="st-detail-item">
                                <div class="st-detail-label">Discount</div>
                                <div class="st-detail-value text-danger">
                                    <i class="fas fa-tag me-1"></i>
                                    − Tk {{ number_format((float) $payment->discount_amount, 2) }}
                                </div>
                            </div>
                        @endif
                        @if (!empty($payment->reference_no))
                            <div class="st-detail-item">
                                <div class="st-detail-label">Reference No</div>
                                <div class="st-detail-value">
                                    <span class="badge bg-info-subtle text-info">{{ $payment->reference_no }}</span>
                                </div>
                            </div>
                        @endif
                        <div class="st-detail-item">
                            <div class="st-detail-label">Notes</div>
                            <div class="st-detail-value">{!! nl2br(e($payment->notes ?: '—')) !!}</div>
                        </div>
                        <div class="st-detail-item">
                            <div class="st-detail-label">Created By</div>
                            <div class="st-detail-value small text-muted">
                                @if ($payment->created_by) User #{{ $payment->created_by }} @else — @endif
                                @if ($payment->created_at) · {{ $payment->created_at->format('d M Y H:i') }} @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Invoice Allocations card --}}
            @if ($payment->settlements && $payment->settlements->isNotEmpty())
                @php $allocTotal = 0; @endphp
                <div class="st-section-card">
                    <div class="st-section-header">
                        <div class="st-section-icon" style="background:linear-gradient(135deg,#2563eb,#3b82f6);">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <h2>Invoice Allocations</h2>
                        <span class="badge bg-success-subtle text-success ms-auto">{{ $payment->settlements->count() }}</span>
                    </div>
                    <div class="st-section-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0 st-gl-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Invoice code</th>
                                        <th>Date</th>
                                        <th class="text-end">Invoice total (Tk)</th>
                                        <th class="text-end">Allocated (Tk)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($payment->settlements as $s)
                                        @php $allocTotal += (float) $s->allocated_amount; @endphp
                                        <tr>
                                            <td>
                                                @if ($s->invoice)
                                                    <a href="{{ route('admin.sales-invoices.show', $s->invoice) }}"
                                                       class="fw-semibold text-decoration-none">
                                                        {{ $s->invoice->invoice_code }}
                                                    </a>
                                                @else
                                                    <span class="text-muted">Invoice #{{ $s->invoice_id }}</span>
                                                @endif
                                            </td>
                                            <td class="small">
                                                @if ($s->invoice)
                                                    {{ \Carbon\Carbon::parse($s->invoice->invoice_date)->format('d M Y') }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if ($s->invoice)
                                                    {{ number_format((float) $s->invoice->total_amount, 2) }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="text-end fw-semibold">
                                                Tk {{ number_format((float) $s->allocated_amount, 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td colspan="3" class="text-end">Total allocated</td>
                                        <td class="text-end">Tk {{ number_format($allocTotal, 2) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- GL Journal Entry card --}}
            @if ($payment->journalEntry)
                @php
                    $je           = $payment->journalEntry;
                    $jeTotalDr    = 0;
                    $jeTotalCr    = 0;
                    foreach ($je->lines as $line) {
                        $jeTotalDr += (float) $line->debit;
                        $jeTotalCr += (float) $line->credit;
                    }
                @endphp
                <div class="st-section-card">
                    <div class="st-section-header">
                        <div class="st-section-icon" style="background:linear-gradient(135deg,#0f172a,#334155);">
                            <i class="fas fa-book"></i>
                        </div>
                        <h2>GL Journal Entry</h2>
                        @if ($je->is_reversed)
                            <span class="badge bg-danger-subtle text-danger ms-auto">
                                <i class="fas fa-rotate-left me-1"></i>Reversed
                            </span>
                        @endif
                    </div>
                    <div class="st-section-body">
                        {{-- GL mini info cards --}}
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <div class="st-gl-mini-card">
                                    <div class="st-gl-mini-label">JE Number</div>
                                    <div class="st-gl-mini-value">{{ $je->entry_no }}</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="st-gl-mini-card">
                                    <div class="st-gl-mini-label">Date</div>
                                    <div class="st-gl-mini-value">{{ \Carbon\Carbon::parse($je->entry_date)->format('d M Y') }}</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="st-gl-mini-card">
                                    <div class="st-gl-mini-label">Source</div>
                                    <div class="st-gl-mini-value">{{ $je->source ?: '—' }}</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="st-gl-mini-card">
                                    <div class="st-gl-mini-label">Status</div>
                                    <div class="st-gl-mini-value">
                                        @if ($je->is_reversed)
                                            <span class="text-danger"><i class="fas fa-rotate-left me-1"></i>Reversed</span>
                                        @else
                                            <span class="text-success"><i class="fas fa-check me-1"></i>Active</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                        @if (!empty($je->description))
                            <div class="mb-3 small text-muted">
                                <i class="fas fa-align-left me-1"></i> {{ $je->description }}
                            </div>
                        @endif
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0 st-gl-table">
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
                                        <td class="text-end debit-col">{{ number_format($jeTotalDr, 2) }}</td>
                                        <td class="text-end credit-col">{{ number_format($jeTotalCr, 2) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Intercompany GL Journal card --}}
            @if ($payment->intercompanyJournalEntry)
                @php
                    $icje        = $payment->intercompanyJournalEntry;
                    $icTotalDr   = 0;
                    $icTotalCr   = 0;
                    foreach ($icje->lines as $line) {
                        $icTotalDr += (float) $line->debit;
                        $icTotalCr += (float) $line->credit;
                    }
                @endphp
                <div class="st-section-card">
                    <div class="st-section-header">
                        <div class="st-section-icon" style="background:linear-gradient(135deg,#0ea5e9,#0284c7);">
                            <i class="fas fa-right-left"></i>
                        </div>
                        <h2>Intercompany GL Journal</h2>
                        @if ($icje->is_reversed)
                            <span class="badge bg-danger-subtle text-danger ms-auto">
                                <i class="fas fa-rotate-left me-1"></i>Reversed
                            </span>
                        @endif
                    </div>
                    <div class="st-section-body">
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <div class="st-gl-mini-card">
                                    <div class="st-gl-mini-label">JE Number</div>
                                    <div class="st-gl-mini-value">{{ $icje->entry_no }}</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="st-gl-mini-card">
                                    <div class="st-gl-mini-label">Date</div>
                                    <div class="st-gl-mini-value">{{ \Carbon\Carbon::parse($icje->entry_date)->format('d M Y') }}</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="st-gl-mini-card">
                                    <div class="st-gl-mini-label">Source</div>
                                    <div class="st-gl-mini-value">{{ $icje->source ?: '—' }}</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="st-gl-mini-card">
                                    <div class="st-gl-mini-label">Status</div>
                                    <div class="st-gl-mini-value">
                                        @if ($icje->is_reversed)
                                            <span class="text-danger"><i class="fas fa-rotate-left me-1"></i>Reversed</span>
                                        @else
                                            <span class="text-success"><i class="fas fa-check me-1"></i>Active</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                        @if (!empty($icje->description))
                            <div class="mb-3 small text-muted">
                                <i class="fas fa-align-left me-1"></i> {{ $icje->description }}
                            </div>
                        @endif
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0 st-gl-table">
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
                                        <td class="text-end debit-col">{{ number_format($icTotalDr, 2) }}</td>
                                        <td class="text-end credit-col">{{ number_format($icTotalCr, 2) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Customer Ledger Entries card --}}
            @if ($customerLedgerEntries->isNotEmpty())
                <div class="st-section-card">
                    <div class="st-section-header">
                        <div class="st-section-icon" style="background:linear-gradient(135deg,#059669,#047857);">
                            <i class="fas fa-list"></i>
                        </div>
                        <h2>Customer Ledger Entries</h2>
                        <span class="badge bg-success-subtle text-success ms-auto">{{ $customerLedgerEntries->count() }}</span>
                    </div>
                    <div class="st-section-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0 st-gl-table">
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
                                    @foreach ($customerLedgerEntries as $cle)
                                        <tr>
                                            <td class="small text-nowrap">
                                                {{ \Carbon\Carbon::parse($cle->transaction_date)->format('d M Y') }}
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">{{ $cle->transaction_type }}</span>
                                            </td>
                                            <td class="text-end debit-col">
                                                @if ((float) $cle->debit > 0)
                                                    {{ number_format((float) $cle->debit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end credit-col">
                                                @if ((float) $cle->credit > 0)
                                                    {{ number_format((float) $cle->credit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end fw-semibold">
                                                {{ number_format((float) $cle->balance, 2) }}
                                            </td>
                                            <td class="small text-muted">
                                                {{ $cle->description ?: '—' }}
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
            @php
                $amountCardClass = '';
                if ($payment->transaction_type === 'discount') $amountCardClass = 'discount-card';
                elseif ($payment->transaction_type === 'write_off') $amountCardClass = 'writeoff-card';
                elseif ($payment->transaction_type === 'payment') $amountCardClass = 'refund-card';
            @endphp
            <div class="st-amount-card {{ $amountCardClass }} mb-3">
                <div class="st-amount-label">Payment Amount</div>
                <div class="st-amount-value">Tk {{ number_format((float) $payment->amount, 2) }}</div>
                @if ((float) $payment->discount_amount > 0)
                    <div class="st-amount-meta">
                        <i class="fas fa-tag me-1"></i>
                        Discount: Tk {{ number_format((float) $payment->discount_amount, 2) }}
                    </div>
                @endif
                <div class="st-amount-meta">
                    <i class="fas fa-calendar me-1"></i>
                    {{ \Carbon\Carbon::parse($payment->payment_date)->format('d M Y') }}
                </div>
            </div>

            {{-- Customer due card --}}
            @php
                $customerDue = 0;
                if ($payment->customer) {
                    $customerDue = (float) \App\Models\CustomerLedger::getBalance($payment->customer_id);
                }
            @endphp
            <div class="st-due-card {{ $customerDue <= 0.01 ? 'zero-due' : '' }} mb-3">
                <div class="st-due-amount" style="{{ $customerDue <= 0.01 ? 'color:#065f46;' : 'color:#92400e;' }}">
                    Tk {{ number_format(abs($customerDue), 2) }}
                </div>
                <div class="st-due-label" style="{{ $customerDue <= 0.01 ? 'color:#065f46;' : 'color:#92400e;' }}">
                    @if ($customerDue <= 0.01)
                        Customer Clear
                    @else
                        Customer Outstanding
                    @endif
                </div>
            </div>

            {{-- Status card --}}
            <div class="st-section-card mb-3">
                <div class="st-section-header">
                    <div class="st-section-icon" style="background:linear-gradient(135deg,#059669,#0d9488);">
                        <i class="fas fa-flag"></i>
                    </div>
                    <h2>Status</h2>
                </div>
                <div class="st-section-body text-center">
                    <div class="mb-2">{!! $statusBadge(true) !!}</div>
                    <div class="small text-muted">
                        @if ($payment->is_reversed)
                            Reversed — GL and customer ledger have been backed out.
                        @else
                            Active — GL posted, customer ledger updated.
                        @endif
                    </div>
                </div>
            </div>

            {{-- Actions card --}}
            <div class="st-section-card">
                <div class="st-section-header">
                    <div class="st-section-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c);">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h2>Actions</h2>
                </div>
                <div class="st-section-body d-grid gap-2">
                    <a href="{{ route('admin.customer-payments.print-receipt', $payment->id) }}" class="btn btn-outline-primary w-100" target="_blank">
                        <i class="fas fa-print me-1"></i> Print Receipt
                    </a>
                    <a href="{{ route('admin.customer-payments.slip', $payment) }}" class="btn btn-outline-secondary w-100" target="_blank">
                        <i class="fas fa-receipt me-1"></i> Print Slip
                    </a>

                    @if (! $payment->is_reversed)
                        <form method="POST" action="{{ route('admin.customer-payments.cancel', $payment) }}" id="cancelForm">
                            @csrf
                            <input type="hidden" name="cancel_reason" id="cancelReasonInput" value="">
                            <button type="button" class="btn btn-outline-danger w-100" id="cancelBtn">
                                <i class="fas fa-rotate-left me-1"></i> Cancel Payment
                            </button>
                        </form>
                        <div class="alert alert-warning small mb-0">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Cancelling reverses the GL journal entry, customer ledger, and invoice allocations
                            (if any). This action cannot be undone.
                        </div>
                    @else
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-ban me-1"></i>
                            This payment is already reversed. No further actions available.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    // ====== Cancel payment (SweetAlert2 prompt for reason — required) ======
    $('#cancelBtn').on('click', function (e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Cancel this payment?',
            html: '<p class="text-muted small mb-2">This will reverse the GL entry, customer ledger, and any invoice allocations. ' +
                  'This action cannot be undone. Please provide a reason:</p>' +
                  '<textarea id="swalCancelReason" class="form-control" rows="3" ' +
                  'placeholder="Reason for cancellation" maxlength="500"></textarea>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-rotate-left"></i> Cancel Payment',
            cancelButtonText: 'Keep Payment',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
            focusConfirm: false,
            preConfirm: function () {
                var reason = $('#swalCancelReason').val();
                if (!reason || !reason.trim()) {
                    Swal.showValidationMessage('A cancellation reason is required.');
                    return false;
                }
                if (reason.trim().length < 5) {
                    Swal.showValidationMessage('Reason must be at least 5 characters.');
                    return false;
                }
                return reason;
            }
        }).then(function (result) {
            if (result.isConfirmed && result.value) {
                $('#cancelReasonInput').val(result.value);
                $('#cancelForm').submit();
            }
        });
    });
});
</script>
@endpush
@endsection
