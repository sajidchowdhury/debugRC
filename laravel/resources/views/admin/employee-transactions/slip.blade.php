@php
    $branchCode = $transaction->branch?->branch_code ?? null;
@endphp
@extends('layouts.print')

@section('print_content')
<div class="print-page position-relative">
    @if ($transaction->is_reversed)
        <div class="watermark">REVERSED</div>
    @endif

    {{-- Company header --}}
    <div class="company-header d-flex justify-content-between align-items-start">
        <div>
            <div class="company-name">{{ config('app.name', 'Remote Center ERP') }}</div>
            @if ($transaction->branch)
                <div class="small text-muted">{{ $transaction->branch->branch_name }}</div>
            @endif
        </div>
        <div class="text-end">
            <div class="doc-title">{{ $transaction->getTransactionTypeLabel() }}</div>
        </div>
    </div>

    {{-- Transaction meta --}}
    <div class="meta-grid">
        <div>
            <div class="meta-label">Voucher No.</div>
            <div class="meta-value">{{ $transaction->transaction_code }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">Date</div>
            <div class="meta-value">{{ \Carbon\Carbon::parse($transaction->transaction_date)->format('d M Y') }}</div>
        </div>
        <div>
            <div class="meta-label">Employee</div>
            <div class="meta-value">{{ $transaction->employee?->name ?? '—' }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">Payment Mode</div>
            <div class="meta-value">
                <span class="badge bg-primary">{{ ucfirst($transaction->payment_mode) }}</span>
            </div>
        </div>
        @if ($transaction->bank)
            <div>
                <div class="meta-label">Bank</div>
                <div class="meta-value">{{ $transaction->bank->bank_name }}</div>
                @if (!empty($transaction->bank->account_no))
                    <div class="small text-muted">A/C: {{ $transaction->bank->account_no }}</div>
                @endif
            </div>
        @endif
    </div>

    {{-- Amount summary --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body text-center py-4">
            @php
                $typeLabels = [
                    'advance'    => 'Advance Amount',
                    'loan'       => 'Loan Amount',
                    'salary'     => 'Salary Amount',
                    'repayment'  => 'Repayment Amount',
                    'deduction'  => 'Deduction Amount',
                    'adjustment' => 'Adjustment Amount',
                ];
                $amountLabel = $typeLabels[$transaction->transaction_type ?? 'advance'] ?? 'Amount';
                $typeColors = [
                    'advance'    => 'text-danger',
                    'loan'       => 'text-danger',
                    'salary'     => 'text-danger',
                    'repayment'  => 'text-success',
                    'deduction'  => 'text-success',
                    'adjustment' => 'text-danger',
                ];
                $amountColor = $typeColors[$transaction->transaction_type ?? 'advance'] ?? 'text-danger';
            @endphp
            <div class="meta-label mb-1">{{ $amountLabel }}</div>
            <div class="display-5 fw-bold {{ $amountColor }}">Tk {{ number_format((float) $transaction->amount, 2) }}</div>
        </div>
    </div>

    {{-- GL posting summary --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="meta-label mb-2">GL Posting</div>
            <div class="row g-2 small">
                <div class="col-6">
                    <span class="text-muted">Journal rule:</span>
                    <strong>{{ $transaction->getGlDescription() }}</strong>
                </div>
                <div class="col-6">
                    <span class="text-muted">Sub-ledger:</span>
                    <strong>
                        @if ($transaction->isOutflow())
                            Debit employee_ledger (increase payable)
                        @else
                            Credit employee_ledger (reduce payable)
                        @endif
                    </strong>
                </div>
            </div>
            @if ($transaction->journalEntry)
                <div class="mt-2 small">
                    <span class="text-muted">JE #:</span>
                    <strong>{{ $transaction->journalEntry->entry_no }}</strong>
                </div>
            @endif
        </div>
    </div>

    {{-- Description --}}
    @if ($transaction->description)
        <div class="mt-3 p-2 bg-light rounded small">
            <strong>Description:</strong> {{ $transaction->description }}
        </div>
    @endif

    {{-- Reversal notice --}}
    @if ($transaction->is_reversed)
        <div class="mt-3 p-2 bg-danger bg-opacity-10 border border-danger rounded small">
            <strong class="text-danger"><i class="fas fa-rotate-left me-1"></i>REVERSED</strong>
            @if (!empty($transaction->reverse_reason))
                <div class="mt-1"><span class="text-muted">Reason:</span> {{ $transaction->reverse_reason }}</div>
            @endif
            @if ($transaction->reversed_at)
                <div class="text-muted">Reversed at: {{ \Carbon\Carbon::parse($transaction->reversed_at)->format('d M Y H:i') }}</div>
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
            <div class="small text-muted">Employee Signature</div>
        </div>
    </div>
</div>
@endsection
