@php
    $modeLabel = function (string $mode): string {
        return [
            'cash' => 'Cash',
            'bank' => 'Bank',
        ][$mode] ?? ucfirst($mode);
    };
@endphp
@extends('layouts.print')

@section('print_content')
<div class="print-page position-relative">
    @if ($expense->is_reversed)
        <div class="watermark">REVERSED</div>
    @endif

    {{-- Company header --}}
    <div class="company-header d-flex justify-content-between align-items-start">
        <div>
            <div class="company-name">{{ config('app.name', 'Remote Center ERP') }}</div>
            @if ($expense->branch)
                <div class="small text-muted">{{ $expense->branch->branch_name }}</div>
            @endif
        </div>
        <div class="text-end">
            <div class="doc-title">Other Expense</div>
            <div class="small text-muted">{{ $modeLabel($expense->payment_mode) }}</div>
        </div>
    </div>

    {{-- Expense meta --}}
    <div class="meta-grid">
        <div>
            <div class="meta-label">Voucher No.</div>
            <div class="meta-value">{{ $expense->expense_code }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">Date</div>
            <div class="meta-value">{{ \Carbon\Carbon::parse($expense->expense_date)->format('d M Y') }}</div>
        </div>
        <div>
            <div class="meta-label">Branch</div>
            <div class="meta-value">{{ $expense->branch?->branch_name ?? '&mdash;' }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">Status</div>
            <div class="meta-value">
                @if ($expense->is_reversed)
                    <span class="badge bg-danger">Reversed</span>
                @else
                    <span class="badge bg-success">Active</span>
                @endif
            </div>
        </div>
        <div>
            <div class="meta-label">Expense Type</div>
            <div class="meta-value">{{ $expense->expense_type ?: '&mdash;' }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">Payment Mode</div>
            <div class="meta-value">
                <span class="badge bg-primary">{{ $modeLabel($expense->payment_mode) }}</span>
            </div>
        </div>
        @if ($expense->payment_mode === 'bank' && $expense->bank)
            <div>
                <div class="meta-label">Bank</div>
                <div class="meta-value">
                    {{ $expense->bank->bank_name }}
                    @if (!empty($expense->bank->account_no))
                        <div class="small text-muted">A/C: {{ $expense->bank->account_no }}</div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- Amount summary --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body text-center py-4">
            <div class="meta-label mb-1">Expense Amount</div>
            <div class="display-5 fw-bold text-danger">Tk {{ number_format((float) $expense->amount, 2) }}</div>
        </div>
    </div>

    {{-- GL posting summary --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="meta-label mb-2">GL Posting</div>
            <div class="row g-2 small">
                <div class="col-6">
                    <span class="text-muted">Journal rule:</span>
                    <strong>Dr Operating Expense / Cr {{ $modeLabel($expense->payment_mode) }}</strong>
                </div>
                <div class="col-6">
                    <span class="text-muted">Debit:</span>
                    <strong>
                        @if ($expense->ledger)
                            {{ $expense->ledger->ledger_name }}
                        @else
                            Operating Expense
                        @endif
                    </strong>
                </div>
                <div class="col-6">
                    <span class="text-muted">Credit:</span>
                    <strong>
                        @if ($expense->payment_mode === 'bank' && $expense->bank)
                            {{ $expense->bank->bank_name }}
                        @else
                            Cash in Hand
                        @endif
                    </strong>
                </div>
            </div>
            @if ($expense->journalEntry)
                <div class="mt-2 small">
                    <span class="text-muted">JE #:</span>
                    <strong>{{ $expense->journalEntry->entry_no }}</strong>
                </div>
            @endif
        </div>
    </div>

    {{-- Notes --}}
    @if ($expense->description)
        <div class="mt-3 p-2 bg-light rounded small">
            <strong>Notes:</strong> {{ $expense->description }}
        </div>
    @endif

    {{-- Reversal notice --}}
    @if ($expense->is_reversed)
        <div class="mt-3 p-2 bg-danger bg-opacity-10 border border-danger rounded small">
            <strong class="text-danger"><i class="fas fa-rotate-left me-1"></i>REVERSED</strong>
            @if (!empty($expense->reverse_reason))
                <div class="mt-1"><span class="text-muted">Reason:</span> {{ $expense->reverse_reason }}</div>
            @endif
            @if ($expense->reversed_at)
                <div class="text-muted">Reversed at: {{ \Carbon\Carbon::parse($expense->reversed_at)->format('d M Y H:i') }}</div>
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
            <div class="small text-muted">Received By</div>
        </div>
    </div>
</div>
@endsection
