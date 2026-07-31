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
        $type = $payment->transaction_type ?? 'payment';
        $badges = [
            'payment' => '<span class="badge bg-success fs-6"><i class="fas fa-money-bill-transfer me-1"></i>Supplier Payment</span>',
            'advance' => '<span class="badge fs-6" style="background:#0d9488;color:#fff;"><i class="fas fa-forward me-1"></i>Advance Payment</span>',
            'receive' => '<span class="badge fs-6" style="background:#7c3aed;color:#fff;"><i class="fas fa-truck-ramp-box me-1"></i>Credit Receive</span>',
        ];
        return $badges[$type] ?? '<span class="badge bg-light text-dark fs-6">' . e($type) . '</span>';
    };

    $typeGradients = [
        'payment' => 'linear-gradient(135deg,#0d9488,#059669)',
        'advance' => 'linear-gradient(135deg,#0d9488,#059669)',
        'receive' => 'linear-gradient(135deg,#7c3aed,#6d28d9)',
    ];
    $heroGradient = $typeGradients[$payment->transaction_type ?? 'payment'] ?? $typeGradients['payment'];

    $typeIcons = [
        'payment' => 'fa-money-bill-transfer',
        'advance' => 'fa-forward',
        'receive' => 'fa-truck-ramp-box',
    ];
    $heroIcon = $typeIcons[$payment->transaction_type ?? 'payment'] ?? $typeIcons['payment'];
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: {{ $heroGradient }};">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas {{ $heroIcon }} me-2"></i>{{ $payment->getTransactionTypeLabel() }} {{ $payment->payment_code }}
                {!! $statusBadge() !!}
                {!! $typeBadge() !!}
            </h1>
            <p class="mb-0 small opacity-75">
                @if ($payment->supplier){{ $payment->supplier->supplier_name }}@endif
                @if ($payment->branch) · {{ $payment->branch->branch_name }}@endif
                · {{ \Carbon\Carbon::parse($payment->payment_date)->format('d M Y') }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.supplier-transactions.slip', ['supplier_transaction' => $payment->id]) }}" class="btn btn-outline-light btn-sm" target="_blank">
                <i class="fas fa-print me-1"></i> Print Slip
            </a>
            <a href="{{ route('admin.supplier-transactions.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Reversal alert --}}
    @if ($payment->is_reversed)
        <div class="alert alert-danger d-flex align-items-start mb-3" role="alert">
            <i class="fas fa-rotate-left me-2 fa-lg text-danger"></i>
            <div class="flex-grow-1">
                <strong>This payment has been reversed.</strong>
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
    @endif

    <div class="row g-3">
        {{-- Left: main details --}}
        <div class="col-lg-8">
            {{-- Payment details card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-circle-info me-1 text-success"></i> Payment details
                    </h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Payment code</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-secondary-subtle text-secondary">{{ $payment->payment_code }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Transaction type</dt>
                        <dd class="col-sm-9">
                            {!! $typeBadge() !!}
                            <span class="text-muted small ms-2">{{ $payment->getGlDescription() }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Payment date</dt>
                        <dd class="col-sm-9">
                            {{ \Carbon\Carbon::parse($payment->payment_date)->format('d M Y') }}
                        </dd>

                        <dt class="col-sm-3 text-muted">Supplier</dt>
                        <dd class="col-sm-9">
                            @if ($payment->supplier)
                                <strong>{{ $payment->supplier->supplier_name }}</strong>
                                <span class="text-muted">({{ $payment->supplier->supplier_code }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">
                            @if ($payment->branch)
                                {{ $payment->branch->branch_name }}
                                <span class="text-muted small">({{ $payment->branch->branch_code }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Payment mode</dt>
                        <dd class="col-sm-9">{!! $modeBadge($payment->payment_mode) !!}</dd>

                        @if ($payment->isBankMode() || $payment->bank)
                            <dt class="col-sm-3 text-muted">Bank</dt>
                            <dd class="col-sm-9">
                                @if ($payment->bank)
                                    <strong>{{ $payment->bank->bank_name }}</strong>
                                    @if (!empty($payment->bank->bank_code))
                                        <span class="text-muted small">({{ $payment->bank->bank_code }})</span>
                                    @endif
                                    @if (!empty($payment->bank->account_no))
                                        <div class="small text-muted">A/C: {{ $payment->bank->account_no }}</div>
                                    @endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </dd>
                        @endif

                        @if ($payment->collectedBy)
                            <dt class="col-sm-3 text-muted">Collected by</dt>
                            <dd class="col-sm-9">
                                {{ $payment->collectedBy->name ?? $payment->collectedBy->employee_code ?? 'Employee #' . $payment->collected_by }}
                            </dd>
                        @endif

                        <dt class="col-sm-3 text-muted">Amount</dt>
                        <dd class="col-sm-9">
                            @if ($payment->isApReduction())
                                <strong class="text-danger fs-5">Tk {{ number_format((float) $payment->amount, 2) }}</strong>
                                <span class="text-muted small ms-1">(outflow)</span>
                            @else
                                <strong class="text-success fs-5">Tk {{ number_format((float) $payment->amount, 2) }}</strong>
                                <span class="text-muted small ms-1">(payable increase)</span>
                            @endif
                        </dd>

                        @if ((float) $payment->discount_amount > 0)
                            <dt class="col-sm-3 text-muted">Discount</dt>
                            <dd class="col-sm-9 text-danger">
                                − Tk {{ number_format((float) $payment->discount_amount, 2) }}
                            </dd>
                        @endif

                        @if (!empty($payment->reference_no))
                            <dt class="col-sm-3 text-muted">Reference no</dt>
                            <dd class="col-sm-9">
                                <span class="badge bg-info-subtle text-info">{{ $payment->reference_no }}</span>
                            </dd>
                        @endif

                        <dt class="col-sm-3 text-muted">Notes</dt>
                        <dd class="col-sm-9">{!! nl2br(e($payment->notes ?: '—')) !!}</dd>

                        <dt class="col-sm-3 text-muted">Created by</dt>
                        <dd class="col-sm-9 small text-muted">
                            @if ($payment->created_by) User #{{ $payment->created_by }} @else — @endif
                            @if ($payment->created_at) · {{ $payment->created_at->format('d M Y H:i') }} @endif
                        </dd>
                    </dl>
                </div>
            </div>

            {{-- GRN Settlements card --}}
            @php
                $settlements = $payment->settlements ?? collect();
            @endphp
            @if ($settlements->isNotEmpty())
                @php $allocTotal = 0; @endphp
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-truck-ramp-box me-1 text-success"></i> GRN allocations
                            <span class="badge bg-success-subtle text-success ms-1">
                                {{ $settlements->count() }}
                            </span>
                        </h2>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>GRN code</th>
                                        <th>Date</th>
                                        <th class="text-end">GRN total (Tk)</th>
                                        <th class="text-end">Settled (Tk)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($settlements as $s)
                                        @php $allocTotal += (float) $s->settled_amount; @endphp
                                        <tr>
                                            <td>
                                                @if (!empty($s->purchaseReceive))
                                                    <span class="fw-semibold">{{ $s->purchaseReceive->receive_code ?? 'GRN #' . $s->purchase_receive_id }}</span>
                                                @else
                                                    <span class="text-muted">GRN #{{ $s->purchase_receive_id }}</span>
                                                @endif
                                            </td>
                                            <td class="small">
                                                @if (!empty($s->purchaseReceive) && !empty($s->purchaseReceive->receive_date))
                                                    {{ \Carbon\Carbon::parse($s->purchaseReceive->receive_date)->format('d M Y') }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if (!empty($s->purchaseReceive))
                                                    {{ number_format((float) ($s->purchaseReceive->total_amount ?? 0), 2) }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="text-end fw-semibold">
                                                Tk {{ number_format((float) $s->settled_amount, 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td colspan="3" class="text-end">Total settled</td>
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
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-book me-1 text-primary"></i> GL Journal Entry
                        </h2>
                        @if ($je->is_reversed)
                            <span class="badge bg-danger-subtle text-danger">
                                <i class="fas fa-rotate-left me-1"></i>Reversed
                            </span>
                        @endif
                    </div>
                    <div class="card-body">
                        <dl class="row mb-3 small">
                            <dt class="col-sm-3 text-muted">JE #</dt>
                            <dd class="col-sm-9">
                                <span class="badge bg-secondary-subtle text-secondary">{{ $je->entry_no }}</span>
                            </dd>
                            <dt class="col-sm-3 text-muted">Date</dt>
                            <dd class="col-sm-9">
                                {{ \Carbon\Carbon::parse($je->entry_date)->format('d M Y') }}
                            </dd>
                            <dt class="col-sm-3 text-muted">Description</dt>
                            <dd class="col-sm-9">{{ $je->description ?: '—' }}</dd>
                            @if (!empty($je->source))
                                <dt class="col-sm-3 text-muted">Source</dt>
                                <dd class="col-sm-9">{{ $je->source }}</dd>
                            @endif
                        </dl>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0">
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
                                            <td class="text-end">
                                                @if ((float) $line->debit > 0)
                                                    {{ number_format((float) $line->debit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
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
                                        <td class="text-end">{{ number_format($jeTotalDr, 2) }}</td>
                                        <td class="text-end">{{ number_format($jeTotalCr, 2) }}</td>
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
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-right-left me-1 text-info"></i> Intercompany GL Journal
                        </h2>
                        @if ($icje->is_reversed)
                            <span class="badge bg-danger-subtle text-danger">
                                <i class="fas fa-rotate-left me-1"></i>Reversed
                            </span>
                        @endif
                    </div>
                    <div class="card-body">
                        <dl class="row mb-3 small">
                            <dt class="col-sm-3 text-muted">JE #</dt>
                            <dd class="col-sm-9">
                                <span class="badge bg-secondary-subtle text-secondary">{{ $icje->entry_no }}</span>
                            </dd>
                            <dt class="col-sm-3 text-muted">Date</dt>
                            <dd class="col-sm-9">
                                {{ \Carbon\Carbon::parse($icje->entry_date)->format('d M Y') }}
                            </dd>
                            <dt class="col-sm-3 text-muted">Description</dt>
                            <dd class="col-sm-9">{{ $icje->description ?: '—' }}</dd>
                            @if (!empty($icje->source))
                                <dt class="col-sm-3 text-muted">Source</dt>
                                <dd class="col-sm-9">{{ $icje->source }}</dd>
                            @endif
                        </dl>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0">
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
                                            <td class="text-end">
                                                @if ((float) $line->debit > 0)
                                                    {{ number_format((float) $line->debit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
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
                                        <td class="text-end">{{ number_format($icTotalDr, 2) }}</td>
                                        <td class="text-end">{{ number_format($icTotalCr, 2) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Supplier Ledger Entries card --}}
            @if ($supplierLedgerEntries->isNotEmpty())
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-list me-1 text-success"></i> Supplier ledger entries
                            <span class="badge bg-success-subtle text-success ms-1">
                                {{ $supplierLedgerEntries->count() }}
                            </span>
                        </h2>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0">
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
                                    @foreach ($supplierLedgerEntries as $sle)
                                        <tr>
                                            <td class="small text-nowrap">
                                                @if (!empty($sle->transaction_date))
                                                    {{ \Carbon\Carbon::parse($sle->transaction_date)->format('d M Y') }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">{{ $sle->transaction_type ?? '—' }}</span>
                                            </td>
                                            <td class="text-end">
                                                @if (!empty($sle->debit) && (float) $sle->debit > 0)
                                                    {{ number_format((float) $sle->debit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if (!empty($sle->credit) && (float) $sle->credit > 0)
                                                    <span class="text-success">
                                                        {{ number_format((float) $sle->credit, 2) }}
                                                    </span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end fw-semibold">
                                                @if (!empty($sle->running_balance))
                                                    {{ number_format((float) $sle->running_balance, 2) }}
                                                @elseif (!empty($sle->balance))
                                                    {{ number_format((float) $sle->balance, 2) }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="small text-muted">
                                                {{ $sle->description ?: '—' }}
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

        {{-- Right: aside actions --}}
        <div class="col-lg-4">
            {{-- Status card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-flag me-1 text-success"></i> Status</h2>
                </div>
                <div class="card-body text-center">
                    <div class="mb-2">{!! $statusBadge(true) !!}</div>
                    <div class="small text-muted">
                        @if ($payment->is_reversed)
                            Reversed — GL and supplier ledger have been backed out.
                        @else
                            Active — GL posted, supplier ledger updated.
                        @endif
                    </div>
                </div>
            </div>

            {{-- Amount card (highlighted) --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body text-center text-white"
                     style="background: {{ $heroGradient }};">
                    <div class="small text-uppercase opacity-75">
                        @if ($payment->isApReduction())
                            Payment / Advance amount
                        @else
                            Credit receive amount
                        @endif
                    </div>
                    <div class="display-6 fw-bold my-2">
                        Tk {{ number_format((float) $payment->amount, 2) }}
                    </div>
                    @if ((float) $payment->discount_amount > 0)
                        <div class="small opacity-75">
                            <i class="fas fa-tag me-1"></i>
                            Discount: Tk {{ number_format((float) $payment->discount_amount, 2) }}
                        </div>
                    @endif
                    <div class="small opacity-75 mt-1">
                        <i class="fas fa-calendar me-1"></i>
                        {{ \Carbon\Carbon::parse($payment->payment_date)->format('d M Y') }}
                    </div>
                </div>
            </div>

            {{-- Supplier due balance card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-taka-sign me-1 text-info"></i> Supplier payable</h2>
                </div>
                <div class="card-body text-center">
                    <div class="small text-muted">Current balance due</div>
                    <div class="h4 fw-bold {{ $supplierDue > 0 ? 'text-danger' : 'text-success' }}">
                        Tk {{ number_format((float) $supplierDue, 2) }}
                    </div>
                    <div class="small text-muted">
                        @if ($supplierDue > 0)
                            <i class="fas fa-arrow-up me-1"></i> We owe this supplier
                        @elseif ($supplierDue < 0)
                            <i class="fas fa-arrow-down me-1"></i> Advance balance (supplier owes us)
                        @else
                            <i class="fas fa-check me-1"></i> Settled
                        @endif
                    </div>
                </div>
            </div>

            {{-- GL Summary card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-calculator me-1 text-primary"></i> GL Summary</h2>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">GL rule</span>
                        <strong class="small">{{ $payment->getGlDescription() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Sub-ledger</span>
                        <strong class="small">
                            @if ($payment->isApReduction())
                                Debit (reduce AP)
                            @else
                                Credit (increase AP)
                            @endif
                        </strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Bank book</span>
                        <strong class="small">
                            @if ($payment->isBankMode() && $payment->isApReduction())
                                Decrease
                            @else
                                No change
                            @endif
                        </strong>
                    </div>
                    @if ($payment->journalEntry)
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small">JE #</span>
                            <span class="badge bg-secondary-subtle text-secondary">{{ $payment->journalEntry->entry_no }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Actions card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-bolt me-1 text-success"></i> Actions</h2>
                </div>
                <div class="card-body d-grid gap-2">
                    {{-- Print slip (dedicated print view) --}}
                    <a href="{{ route('admin.supplier-transactions.slip', ['supplier_transaction' => $payment->id]) }}" class="btn btn-outline-primary w-100" target="_blank">
                        <i class="fas fa-print me-1"></i> Print Slip
                    </a>

                    @if (! $payment->is_reversed)
                        <button type="button" class="btn btn-outline-danger w-100" id="reverseBtn">
                            <i class="fas fa-rotate-left me-1"></i> Reverse Payment
                        </button>
                        <div class="alert alert-warning small mb-0">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Reversing backs out the GL journal entry, supplier ledger, and any GRN allocations.
                            Bank balance will be restored (if bank mode). This action cannot be undone.
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

{{-- Boot config for SupplierTransaction.js --}}
<script>
    window.ST_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ csrf_token() }}',
        paymentId: {{ $payment->id }},
        paymentCode: '{{ $payment->payment_code }}',
        routes: {
            'index': '{{ route("admin.supplier-transactions.index") }}',
            'show': '{{ route("admin.supplier-transactions.show", ["supplier_transaction" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'reverse': '{{ route("admin.supplier-transactions.reverse", ["supplier_transaction" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'slip': '{{ route("admin.supplier-transactions.slip", ["supplier_transaction" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'supplier-show': '{{ url("/admin/suppliers") }}/',
        },
    };
</script>

@push('scripts')
<script src="/assets/js/SupplierTransaction.js"></script>
<script>
$(function () {
    // ====== Reverse payment (SweetAlert2 prompt for reason — required) ======
    $('#reverseBtn').on('click', function (e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Reverse this payment?',
            html: '<p class="text-muted small mb-2">This will reverse the GL entry, supplier ledger, and any GRN allocations. ' +
                  'Bank balance will be restored (if bank mode). This action cannot be undone. Please provide a reason:</p>' +
                  '<textarea id="swalReverseReason" class="form-control" rows="3" ' +
                  'placeholder="Reason for reversal" maxlength="500"></textarea>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-rotate-left"></i> Reverse Payment',
            cancelButtonText: 'Keep Payment',
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
                    url: '/admin/supplier-transactions/' + window.ST_BOOT.paymentId + '/reverse?XTransformPort=' + window.ST_BOOT.baseUrl.split('/').pop(),
                    method: 'POST',
                    data: {
                        _token: window.ST_BOOT.csrf_token,
                        reverse_reason: result.value
                    },
                    success: function (resp) {
                        if (resp.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Reversed!',
                                text: resp.message || 'Payment reversed successfully.',
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
