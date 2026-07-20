@extends('layouts.print')

@section('print_content')
@php
    $itemsPerPage = 17;
    $allItems = $invoice->items;
    $totalPages = max(1, ceil($allItems->count() / $itemsPerPage));
    $globalSl = 0;
@endphp

@for ($page = 1; $page <= $totalPages; $page++)
    @php
        $pageItems = $allItems->slice(($page - 1) * $itemsPerPage, $itemsPerPage);
        $isLastPage = ($page === $totalPages);
    @endphp

    <div class="print-page position-relative">
        @if ($invoice->is_reversed)
            <div class="watermark">CANCELLED</div>
        @endif

        {{-- Company header --}}
        <div class="company-header d-flex justify-content-between align-items-start">
            <div>
                <div class="company-name">{{ config('app.name', 'Remote Center ERP') }}</div>
                @if ($invoice->branch)
                    <div class="small text-muted">{{ $invoice->branch->branch_name }}</div>
                @endif
            </div>
            <div class="text-end">
                <div class="doc-title">Sales Invoice</div>
                <div class="small text-muted">Page {{ $page }} of {{ $totalPages }}</div>
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
                @if ($invoice->customer?->mobile)
                    <div class="small text-muted">{{ $invoice->customer->mobile }}</div>
                @endif
            </div>
            <div class="text-end">
                @if ($invoice->sales_person)
                    <div class="meta-label">Sales Person</div>
                    <div class="meta-value">{{ $invoice->sales_person }}</div>
                @endif
                @if ($invoice->dispatchers && $invoice->dispatchers->count() > 0)
                    <div class="meta-label mt-1">Dispatchers</div>
                    <div class="meta-value">{{ $invoice->dispatchers->pluck('name')->join(', ') }}</div>
                @endif
            </div>
        </div>

        {{-- Items table --}}
        <table class="table table-sm table-bordered items-table">
            <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <th style="width:45%;">Product</th>
                    <th class="text-end" style="width:12%;">Qty</th>
                    <th class="text-end" style="width:15%;">Rate (Tk)</th>
                    <th class="text-end" style="width:18%;">Amount (Tk)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pageItems as $item)
                    <tr>
                        <td>{{ ++$globalSl }}</td>
                        <td>
                            {{ $item->product?->product_name ?? 'Product #' . $item->product_id }}
                            @if ($item->condition_state === 'Damage')
                                <span class="badge bg-danger-subtle text-danger ms-1">Damage</span>
                            @endif
                        </td>
                        <td class="text-end">{{ number_format((float) $item->qty, 4) }}</td>
                        <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                        <td class="text-end fw-semibold">{{ number_format((float) $item->qty * (float) $item->rate, 2) }}</td>
                    </tr>
                @endforeach
                {{-- Fill empty rows on last page for alignment --}}
                @for ($i = $pageItems->count(); $i < $itemsPerPage && !$isLastPage; $i++)
                    <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
                @endfor
            </tbody>
        </table>

        {{-- Totals on last page only --}}
        @if ($isLastPage)
            <div class="totals-section">
                <dl class="row mb-0">
                    <dt class="col-7">Subtotal</dt>
                    <dd class="col-5">Tk {{ number_format((float) $invoice->sub_total, 2) }}</dd>

                    @if ((float) $invoice->discount_amount > 0)
                        <dt class="col-7 text-danger">Discount</dt>
                        <dd class="col-5 text-danger">− Tk {{ number_format((float) $invoice->discount_amount, 2) }}</dd>
                    @endif

                    @if ((float) $invoice->transport_cost > 0)
                        <dt class="col-7 text-success">Transport</dt>
                        <dd class="col-5 text-success">+ Tk {{ number_format((float) $invoice->transport_cost, 2) }}</dd>
                    @endif

                    <dt class="col-7 border-top pt-1"><strong>Total Amount</strong></dt>
                    <dd class="col-5 border-top pt-1"><strong class="fs-5 text-primary">Tk {{ number_format((float) $invoice->total_amount, 2) }}</strong></dd>

                    @if ((float) $invoice->paid_amount > 0)
                        <dt class="col-7 text-success">Paid</dt>
                        <dd class="col-5 text-success">Tk {{ number_format((float) $invoice->paid_amount, 2) }}</dd>
                    @endif

                    @if ((float) $invoice->due_amount > 0)
                        <dt class="col-7 text-danger">Due</dt>
                        <dd class="col-5 text-danger"><strong>Tk {{ number_format((float) $invoice->due_amount, 2) }}</strong></dd>
                    @endif
                </dl>
            </div>

            {{-- Notes --}}
            @if ($invoice->notes)
                <div class="mt-3 p-2 bg-light rounded small">
                    <strong>Notes:</strong> {{ $invoice->notes }}
                </div>
            @endif

            {{-- Signatures --}}
            <div class="signature-section">
                <div class="signature-box">
                    <div class="small text-muted">Customer Signature</div>
                </div>
                <div class="signature-box">
                    <div class="small text-muted">Authorized Signature</div>
                </div>
            </div>
        @endif
    </div>
@endfor
@endsection
