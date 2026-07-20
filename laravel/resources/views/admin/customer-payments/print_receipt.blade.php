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
            <div class="doc-title">Payment Receipt</div>
        </div>
    </div>

    {{-- Payment meta --}}
    <div class="meta-grid">
        <div>
            <div class="meta-label">Receipt No.</div>
            <div class="meta-value">{{ $payment->payment_code }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">Date</div>
            <div class="meta-value">{{ \Carbon\Carbon::parse($payment->payment_date)->format('d M Y') }}</div>
        </div>
        <div>
            <div class="meta-label">Customer</div>
            <div class="meta-value">{{ $payment->customer?->customer_name ?? '—' }}</div>
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
            </div>
        @endif
        @if ($payment->reference_no)
            <div class="text-end">
                <div class="meta-label">Reference No.</div>
                <div class="meta-value">{{ $payment->reference_no }}</div>
            </div>
        @endif
    </div>

    {{-- Amount summary --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body text-center py-4">
            <div class="meta-label mb-1">Amount Received</div>
            <div class="display-5 fw-bold text-success">Tk {{ number_format((float) $payment->amount, 2) }}</div>
            @if ((float) $payment->discount_amount > 0)
                <div class="small text-muted mt-1">Discount: Tk {{ number_format((float) $payment->discount_amount, 2) }}</div>
            @endif
        </div>
    </div>

    {{-- Invoice allocations --}}
    @if ($payment->allocations && $payment->allocations->isNotEmpty())
        <div class="meta-label mb-2">Invoice Allocations</div>
        <table class="table table-sm table-bordered items-table">
            <thead>
                <tr>
                    <th style="width:10%;">#</th>
                    <th style="width:40%;">Invoice No.</th>
                    <th class="text-end" style="width:25%;">Invoice Total</th>
                    <th class="text-end" style="width:25%;">Allocated (Tk)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payment->allocations as $idx => $allocation)
                    <tr>
                        <td>{{ $idx + 1 }}</td>
                        <td>{{ $allocation->invoice?->invoice_code ?? '—' }}</td>
                        <td class="text-end">{{ number_format((float) ($allocation->invoice?->total_amount ?? 0), 2) }}</td>
                        <td class="text-end fw-semibold">{{ number_format((float) $allocation->allocated_amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="table-light">
                    <th colspan="3" class="text-end">Total Allocated</th>
                    <th class="text-end">{{ number_format($payment->allocations->sum('allocated_amount'), 2) }}</th>
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

    {{-- Signatures --}}
    <div class="signature-section">
        <div class="signature-box">
            <div class="small text-muted">Received By</div>
        </div>
        <div class="signature-box">
            <div class="small text-muted">Customer Signature</div>
        </div>
    </div>
</div>
@endsection
