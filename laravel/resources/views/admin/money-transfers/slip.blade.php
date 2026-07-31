@php
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
            'cash_to_bank' => 'Dr Bank · Cr Cash',
            'bank_to_cash' => 'Dr Cash · Cr Bank',
            'cash_to_cash' => 'Dr Cash (to branch) · Cr Cash (from branch)',
            'bank_to_bank' => 'Dr Bank (to) · Cr Bank (from)',
        ][$type] ?? '—';
    };
@endphp
@extends('layouts.print')

@section('print_content')
<div class="print-page position-relative">
    @if ($transfer->is_reversed)
        <div class="watermark">REVERSED</div>
    @endif

    {{-- Company header --}}
    <div class="company-header d-flex justify-content-between align-items-start">
        <div>
            <div class="company-name">{{ config('app.name', 'Remote Center ERP') }}</div>
            @if ($transfer->fromBranch)
                <div class="small text-muted">{{ $transfer->fromBranch->branch_name }}</div>
            @endif
        </div>
        <div class="text-end">
            <div class="doc-title">Money Transfer</div>
            <div class="small text-muted">{{ $typeLabel($transfer->transfer_type) }}</div>
        </div>
    </div>

    {{-- Transfer meta --}}
    <div class="meta-grid">
        <div>
            <div class="meta-label">Voucher No.</div>
            <div class="meta-value">{{ $transfer->transfer_code }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">Date</div>
            <div class="meta-value">{{ \Carbon\Carbon::parse($transfer->transfer_date)->format('d M Y') }}</div>
        </div>
        <div>
            <div class="meta-label">Transfer Type</div>
            <div class="meta-value">
                <span class="badge bg-primary">{{ $typeLabel($transfer->transfer_type) }}</span>
            </div>
        </div>
        <div class="text-end">
            <div class="meta-label">Status</div>
            <div class="meta-value">
                @if ($transfer->is_reversed)
                    <span class="badge bg-danger">Reversed</span>
                @else
                    <span class="badge bg-success">Active</span>
                @endif
            </div>
        </div>
        <div>
            <div class="meta-label">From Branch</div>
            <div class="meta-value">{{ $transfer->fromBranch?->branch_name ?? '&mdash;' }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">To Branch</div>
            <div class="meta-value">{{ $transfer->toBranch?->branch_name ?? '&mdash;' }}</div>
        </div>
        @if (in_array($transfer->transfer_type, ['bank_to_cash', 'bank_to_bank']))
            <div>
                <div class="meta-label">From Bank</div>
                <div class="meta-value">
                    {{ $transfer->fromBank?->bank_name ?? '&mdash;' }}
                    @if ($transfer->fromBank && !empty($transfer->fromBank->account_no))
                        <div class="small text-muted">A/C: {{ $transfer->fromBank->account_no }}</div>
                    @endif
                </div>
            </div>
        @endif
        @if (in_array($transfer->transfer_type, ['cash_to_bank', 'bank_to_bank']))
            <div class="text-end">
                <div class="meta-label">To Bank</div>
                <div class="meta-value">
                    {{ $transfer->toBank?->bank_name ?? '&mdash;' }}
                    @if ($transfer->toBank && !empty($transfer->toBank->account_no))
                        <div class="small text-muted">A/C: {{ $transfer->toBank->account_no }}</div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- Amount summary --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body text-center py-4">
            <div class="meta-label mb-1">Transfer Amount</div>
            <div class="display-5 fw-bold text-success">Tk {{ number_format((float) $transfer->amount, 2) }}</div>
        </div>
    </div>

    {{-- GL posting summary --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="meta-label mb-2">GL Posting</div>
            <div class="row g-2 small">
                <div class="col-6">
                    <span class="text-muted">Journal rule:</span>
                    <strong>{{ $glInfo($transfer->transfer_type) }}</strong>
                </div>
                <div class="col-6">
                    <span class="text-muted">Cash ledger:</span>
                    <strong>
                        @if (in_array($transfer->transfer_type, ['cash_to_bank', 'cash_to_cash']))
                            Credit (reduce cash)
                        @elseif (in_array($transfer->transfer_type, ['bank_to_cash']))
                            Debit (increase cash)
                        @else
                            No change
                        @endif
                    </strong>
                </div>
                <div class="col-6">
                    <span class="text-muted">Bank book:</span>
                    <strong>
                        @if (in_array($transfer->transfer_type, ['cash_to_bank', 'bank_to_bank']))
                            Debit (increase bank)
                        @elseif (in_array($transfer->transfer_type, ['bank_to_cash']))
                            Credit (decrease bank)
                        @else
                            No change
                        @endif
                    </strong>
                </div>
            </div>
            @if ($transfer->journalEntry)
                <div class="mt-2 small">
                    <span class="text-muted">JE #:</span>
                    <strong>{{ $transfer->journalEntry->entry_no }}</strong>
                </div>
            @endif
        </div>
    </div>

    {{-- Notes --}}
    @if ($transfer->notes)
        <div class="mt-3 p-2 bg-light rounded small">
            <strong>Notes:</strong> {{ $transfer->notes }}
        </div>
    @endif

    {{-- Reversal notice --}}
    @if ($transfer->is_reversed)
        <div class="mt-3 p-2 bg-danger bg-opacity-10 border border-danger rounded small">
            <strong class="text-danger"><i class="fas fa-rotate-left me-1"></i>REVERSED</strong>
            @if (!empty($transfer->reverse_reason))
                <div class="mt-1"><span class="text-muted">Reason:</span> {{ $transfer->reverse_reason }}</div>
            @endif
            @if ($transfer->reversed_at)
                <div class="text-muted">Reversed at: {{ \Carbon\Carbon::parse($transfer->reversed_at)->format('d M Y H:i') }}</div>
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
