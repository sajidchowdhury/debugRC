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
    @if ($income->is_reversed)
        <div class="watermark">REVERSED</div>
    @endif

    {{-- Company header --}}
    <div class="company-header d-flex justify-content-between align-items-start">
        <div>
            <div class="company-name">{{ config('app.name', 'Remote Center ERP') }}</div>
            @if ($income->branch)
                <div class="small text-muted">{{ $income->branch->branch_name }}</div>
            @endif
        </div>
        <div class="text-end">
            <div class="doc-title">Other Income Voucher</div>
            <div class="small text-muted">{{ $income->income_type ?: 'General Income' }}</div>
        </div>
    </div>

    {{-- Income meta --}}
    <div class="meta-grid">
        <div>
            <div class="meta-label">Voucher No.</div>
            <div class="meta-value">{{ $income->income_code }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">Date</div>
            <div class="meta-value">{{ \Carbon\Carbon::parse($income->income_date)->format('d M Y') }}</div>
        </div>
        <div>
            <div class="meta-label">Branch</div>
            <div class="meta-value">{{ $income->branch?->branch_name ?? '&mdash;' }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">Status</div>
            <div class="meta-value">
                @if ($income->is_reversed)
                    <span class="badge bg-danger">Reversed</span>
                @else
                    <span class="badge bg-success">Active</span>
                @endif
            </div>
        </div>
        <div>
            <div class="meta-label">Income Type</div>
            <div class="meta-value">
                @if ($income->income_type)
                    <span class="badge bg-success">{{ $income->income_type }}</span>
                @else
                    &mdash;
                @endif
            </div>
        </div>
        <div class="text-end">
            <div class="meta-label">Payment Mode</div>
            <div class="meta-value">
                <span class="badge {{ $income->payment_mode === 'bank' ? 'bg-primary' : 'bg-secondary' }}">
                    {{ $modeLabel($income->payment_mode) }}
                </span>
            </div>
        </div>
        @if ($income->payment_mode === 'bank' && $income->bank)
            <div>
                <div class="meta-label">Bank</div>
                <div class="meta-value">
                    {{ $income->bank->bank_name }}
                    @if (!empty($income->bank->account_no))
                        <div class="small text-muted">A/C: {{ $income->bank->account_no }}</div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- Amount summary --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body text-center py-4">
            <div class="meta-label mb-1">Income Amount</div>
            <div class="display-5 fw-bold text-success">Tk {{ number_format((float) $income->amount, 2) }}</div>
        </div>
    </div>

    {{-- GL posting summary --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="meta-label mb-2">GL Posting</div>
            <div class="row g-2 small">
                <div class="col-6">
                    <span class="text-muted">Journal rule:</span>
                    <strong>Dr {{ $modeLabel($income->payment_mode) }} · Cr Income Ledger</strong>
                </div>
                <div class="col-6">
                    <span class="text-muted">Sub-ledger:</span>
                    <strong>None — CoA only</strong>
                </div>
            </div>
            @if ($income->journalEntry)
                <div class="mt-2 small">
                    <span class="text-muted">JE #:</span>
                    <strong>{{ $income->journalEntry->entry_no }}</strong>
                </div>
            @endif
        </div>
    </div>

    {{-- Notes --}}
    @if ($income->description)
        <div class="mt-3 p-2 bg-light rounded small">
            <strong>Notes:</strong> {{ $income->description }}
        </div>
    @endif

    {{-- Reversal notice --}}
    @if ($income->is_reversed)
        <div class="mt-3 p-2 bg-danger bg-opacity-10 border border-danger rounded small">
            <strong class="text-danger"><i class="fas fa-rotate-left me-1"></i>REVERSED</strong>
            @if (!empty($income->reverse_reason))
                <div class="mt-1"><span class="text-muted">Reason:</span> {{ $income->reverse_reason }}</div>
            @endif
            @if ($income->reversed_at)
                <div class="text-muted">Reversed at: {{ \Carbon\Carbon::parse($income->reversed_at)->format('d M Y H:i') }}</div>
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
