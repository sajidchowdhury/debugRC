@extends('layouts.print')

@section('print_content')
{{-- Godown copy = picking list for warehouse staff (pre-challan) --}}
<div class="print-page position-relative">
    {{-- Company header --}}
    <div class="company-header d-flex justify-content-between align-items-start">
        <div>
            <div class="company-name">{{ config('app.name', 'Remote Center ERP') }}</div>
            @if ($invoice->branch)
                <div class="small text-muted">{{ $invoice->branch->branch_name }}</div>
            @endif
        </div>
        <div class="text-end">
            <div class="doc-title">Godown Copy</div>
            <div class="small text-muted">Picking List</div>
        </div>
    </div>

    {{-- Invoice meta --}}
    <div class="meta-grid">
        <div>
            <div class="meta-label">Invoice No.</div>
            <div class="meta-value">{{ $invoice->invoice_code }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">Date</div>
            <div class="meta-value">{{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') }}</div>
        </div>
        <div>
            <div class="meta-label">Customer</div>
            <div class="meta-value">{{ $invoice->customer?->customer_name ?? '—' }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">Godown Status</div>
            <div class="meta-value">
                @if ($invoice->is_godown_prepared)
                    <span class="badge bg-success">Prepared</span>
                @else
                    <span class="badge bg-warning">Pending</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Picking list table --}}
    <table class="table table-sm table-bordered items-table">
        <thead>
            <tr>
                <th style="width:5%;">#</th>
                <th style="width:35%;">Product</th>
                <th style="width:20%;">Warehouse</th>
                <th class="text-end" style="width:12%;">Ordered Qty</th>
                <th class="text-center" style="width:12%;">Picked Qty</th>
                <th class="text-center" style="width:16%;">Signature</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $idx => $item)
                <tr>
                    <td>{{ $idx + 1 }}</td>
                    <td>
                        {{ $item->product?->product_name ?? 'Product #' . $item->product_id }}
                        @if ($item->product?->product_code)
                            <br><small class="text-muted">{{ $item->product->product_code }}</small>
                        @endif
                    </td>
                    <td>
                        @if ($item->warehouse_id)
                            {{ $item->warehouse?->warehouse_name ?? 'WH #' . $item->warehouse_id }}
                        @else
                            <span class="text-warning">Not assigned</span>
                        @endif
                    </td>
                    <td class="text-end fw-semibold">{{ number_format((float) $item->qty, 4) }}</td>
                    <td class="text-center">
                        <span style="border-bottom: 1px solid #999; display: inline-block; width: 80px;">&nbsp;</span>
                    </td>
                    <td class="text-center">
                        <span style="border-bottom: 1px solid #999; display: inline-block; width: 100px;">&nbsp;</span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Summary --}}
    <div class="totals-section">
        <dl class="row mb-0">
            <dt class="col-7">Total Items</dt>
            <dd class="col-5">{{ $invoice->items->count() }}</dd>
            <dt class="col-7">Total Qty</dt>
            <dd class="col-5">{{ number_format($invoice->items->sum(fn($i) => (float) $i->qty), 4) }}</dd>
            <dt class="col-7">Invoice Total</dt>
            <dd class="col-5"><strong>Tk {{ number_format((float) $invoice->total_amount, 2) }}</strong></dd>
        </dl>
    </div>

    {{-- Notes for warehouse staff --}}
    <div class="mt-3 p-2 bg-warning-subtle rounded small">
        <i class="fas fa-triangle-exclamation me-1"></i>
        <strong>Instructions:</strong> Verify each product + quantity before dispatch.
        Mark picked qty + sign each line. Return this copy to the office after loading.
    </div>

    {{-- Signatures --}}
    <div class="signature-section">
        <div class="signature-box">
            <div class="small text-muted">Picked By</div>
        </div>
        <div class="signature-box">
            <div class="small text-muted">Checked By</div>
        </div>
        <div class="signature-box">
            <div class="small text-muted">Dispatched By</div>
        </div>
    </div>
</div>
@endsection
