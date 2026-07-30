@php
    $branchCode = $payment->branch?->branch_code ?? null;
@endphp
@extends('layouts.print')

@section('print_content')
<div class="print-page position-relative">
    @if ($payment->is_reversed)
        <div class="watermark">REVERSED</div>
    @endif

    {{-- Company header --}}
    <div class="company-header d-flex justify-content-between align-items-start">
        <div>
            <div class="company-name">{{ config('app.name', 'Remote Center ERP') }}</div>
            @if ($payment->branch)
                <div class="small text-muted">{{ $payment->branch->branch_name }}</div>
            @endif
        </div>
        <div class="text-end">
            <div class="doc-title">{{ $payment->getTransactionTypeLabel() }}</div>
        </div>
    </div>

    {{-- Payment meta --}}
    <div class="meta-grid">
        <div>
            <div class="meta-label">Voucher No.</div>
            <div class="meta-value">{{ $payment->payment_code }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">Date</div>
            <div class="meta-value">{{ \Carbon\Carbon::parse($payment->payment_date)->format('d M Y') }}</div>
        </div>
        <div>
            <div class="meta-label">Supplier</div>
            <div class="meta-value">{{ $payment->supplier?->supplier_name ?? '—' }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">Payment Mode</div>
            <div class="meta-value">
                <span class="badge bg-primary">{{ ucfirst($payment->payment_mode) }}</span>
            </div>
        </div>
        @if ($payment->bank)
            <div>
                <div class="meta-label">Bank</div>
                <div class="meta-value">{{ $payment->bank->bank_name }}</div>
                @if (!empty($payment->bank->account_no))
                    <div class="small text-muted">A/C: {{ $payment->bank->account_no }}</div>
                @endif
            </div>
        @endif
        @if (!empty($payment->reference_no))
            <div class="text-end">
                <div class="meta-label">Reference No.</div>
                <div class="meta-value">{{ $payment->reference_no }}</div>
            </div>
        @endif
    </div>

    {{-- Amount summary --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body text-center py-4">
            @php
                $typeLabels = [
                    'payment' => 'Payment Amount',
                    'advance' => 'Advance Amount',
                    'receive' => 'Receive Amount',
                ];
                $amountLabel = $typeLabels[$payment->transaction_type ?? 'payment'] ?? 'Amount';
                $typeColors = [
                    'payment' => 'text-success',
                    'advance' => 'text-success',
                    'receive' => 'text-primary',
                ];
                $amountColor = $typeColors[$payment->transaction_type ?? 'payment'] ?? 'text-success';
            @endphp
            <div class="meta-label mb-1">{{ $amountLabel }}</div>
            <div class="display-5 fw-bold {{ $amountColor }}">Tk {{ number_format((float) $payment->amount, 2) }}</div>
            @if ((float) $payment->discount_amount > 0)
                <div class="small text-muted mt-1">Discount: Tk {{ number_format((float) $payment->discount_amount, 2) }}</div>
            @endif
        </div>
    </div>

    {{-- GL posting summary --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="meta-label mb-2">GL Posting</div>
            <div class="row g-2 small">
                <div class="col-6">
                    <span class="text-muted">Journal rule:</span>
                    <strong>{{ $payment->getGlDescription() }}</strong>
                </div>
                <div class="col-6">
                    <span class="text-muted">Sub-ledger:</span>
                    <strong>
                        @if ($payment->isApReduction())
                            Debit supplier_ledger (reduce AP)
                        @else
                            Credit supplier_ledger (increase AP)
                        @endif
                    </strong>
                </div>
            </div>
            @if ($payment->journalEntry)
                <div class="mt-2 small">
                    <span class="text-muted">JE #:</span>
                    <strong>{{ $payment->journalEntry->entry_no }}</strong>
                </div>
            @endif
        </div>
    </div>

    {{-- GRN allocations --}}
    @php
        $settlements = $payment->settlements ?? collect();
    @endphp
    @if ($settlements->isNotEmpty())
        <div class="meta-label mb-2">GRN Allocations</div>
        <table class="table table-sm table-bordered items-table">
            <thead>
                <tr>
                    <th style="width:10%;">#</th>
                    <th style="width:40%;">GRN Code</th>
                    <th class="text-end" style="width:25%;">GRN Total (Tk)</th>
                    <th class="text-end" style="width:25%;">Settled (Tk)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($settlements as $idx => $s)
                    <tr>
                        <td>{{ $idx + 1 }}</td>
                        <td>{{ $s->purchaseReceive?->receive_code ?? 'GRN #' . $s->purchase_receive_id }}</td>
                        <td class="text-end">{{ number_format((float) ($s->purchaseReceive?->total_amount ?? 0), 2) }}</td>
                        <td class="text-end fw-semibold">{{ number_format((float) $s->settled_amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="table-light">
                    <th colspan="3" class="text-end">Total Settled</th>
                    <th class="text-end">{{ number_format($settlements->sum('settled_amount'), 2) }}</th>
                </tr>
            </tfoot>
        </table>
    @endif

    {{-- Notes --}}
    @if ($payment->notes)
        <div class="mt-3 p-2 bg-light rounded small">
            <strong>Notes:</strong> {{ $payment->notes }}
        </div>
    @endif

    {{-- Reversal notice --}}
    @if ($payment->is_reversed)
        <div class="mt-3 p-2 bg-danger bg-opacity-10 border border-danger rounded small">
            <strong class="text-danger"><i class="fas fa-rotate-left me-1"></i>REVERSED</strong>
            @if (!empty($payment->reverse_reason))
                <div class="mt-1"><span class="text-muted">Reason:</span> {{ $payment->reverse_reason }}</div>
            @endif
            @if ($payment->reversed_at)
                <div class="text-muted">Reversed at: {{ \Carbon\Carbon::parse($payment->reversed_at)->format('d M Y H:i') }}</div>
            @endif
        </div>
    @endif

    {{-- Signatures --}}
    <div class="signature-section">
        <div class="signature-box">
            <div class="small text-muted">Prepared By</div>
        </div>
        <div class="signature-box">
            <div class="small text-muted">Approved By</div>
        </div>
        <div class="signature-box">
            <div class="small text-muted">Supplier Signature</div>
        </div>
    </div>
</div>
@endsection
