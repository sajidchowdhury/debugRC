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
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#059669,#0d9488);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-hand-holding-dollar me-2"></i>Payment {{ $payment->payment_code }}
                {!! $statusBadge() !!}
            </h1>
            <p class="mb-0 small opacity-75">
                @if ($payment->customer){{ $payment->customer->customer_name }}@endif
                @if ($payment->branch) · {{ $payment->branch->branch_name }}@endif
                · {{ \Carbon\Carbon::parse($payment->payment_date)->format('d M Y') }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-light btn-sm" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print
            </button>
            <a href="{{ route('admin.customer-payments.index') }}" class="btn btn-outline-light btn-sm">
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

                        <dt class="col-sm-3 text-muted">Payment date</dt>
                        <dd class="col-sm-9">
                            {{ \Carbon\Carbon::parse($payment->payment_date)->format('d M Y') }}
                        </dd>

                        <dt class="col-sm-3 text-muted">Customer</dt>
                        <dd class="col-sm-9">
                            @if ($payment->customer)
                                <strong>{{ $payment->customer->customer_name }}</strong>
                                <span class="text-muted">({{ $payment->customer->customer_code }})</span>
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

                        <dt class="col-sm-3 text-muted">Amount</dt>
                        <dd class="col-sm-9">
                            <strong class="text-success fs-5">Tk {{ number_format((float) $payment->amount, 2) }}</strong>
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

            {{-- Invoice allocations card --}}
            @if ($payment->settlements && $payment->settlements->isNotEmpty())
                @php $allocTotal = 0; @endphp
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-file-invoice-dollar me-1 text-success"></i> Invoice allocations
                            <span class="badge bg-success-subtle text-success ms-1">
                                {{ $payment->settlements->count() }}
                            </span>
                        </h2>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
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

            {{-- Customer Ledger Entries card --}}
            @if ($customerLedgerEntries->isNotEmpty())
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-list me-1 text-success"></i> Customer ledger entries
                            <span class="badge bg-success-subtle text-success ms-1">
                                {{ $customerLedgerEntries->count() }}
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
                                    @foreach ($customerLedgerEntries as $cle)
                                        <tr>
                                            <td class="small text-nowrap">
                                                {{ \Carbon\Carbon::parse($cle->transaction_date)->format('d M Y') }}
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">{{ $cle->transaction_type }}</span>
                                            </td>
                                            <td class="text-end">
                                                @if ((float) $cle->debit > 0)
                                                    {{ number_format((float) $cle->debit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if ((float) $cle->credit > 0)
                                                    <span class="text-success">
                                                        {{ number_format((float) $cle->credit, 2) }}
                                                    </span>
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
                            Reversed — GL and customer ledger have been backed out.
                        @else
                            Active — GL posted, customer ledger updated.
                        @endif
                    </div>
                </div>
            </div>

            {{-- Amount card (highlighted) --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body text-center text-white"
                     style="background: linear-gradient(135deg,#059669,#0d9488);">
                    <div class="small text-uppercase opacity-75">Payment amount</div>
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

            {{-- Actions card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-bolt me-1 text-success"></i> Actions</h2>
                </div>
                <div class="card-body d-grid gap-2">
                    {{-- P1-6: Print receipt (dedicated print view) --}}
                    <a href="{{ route('admin.customer-payments.print-receipt', $payment->id) }}" class="btn btn-outline-primary w-100" target="_blank">
                        <i class="fas fa-print me-1"></i> Print Receipt
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
