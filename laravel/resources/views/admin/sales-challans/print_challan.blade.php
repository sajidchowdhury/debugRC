@extends('layouts.print')

@section('print_content')
@php
    $itemsPerPage = 17;
    $allItems = $challan->items ?? collect();
    $totalPages = max(1, ceil($allItems->count() / $itemsPerPage));
    $globalSl = 0;
@endphp

@for ($page = 1; $page <= $totalPages; $page++)
    @php
        $pageItems = $allItems->slice(($page - 1) * $itemsPerPage, $itemsPerPage);
        $isLastPage = ($page === $totalPages);
    @endphp

    <div class="print-page position-relative">
        @if ($challan->is_reversed)
            <div class="watermark">CANCELLED</div>
        @endif

        {{-- Company header --}}
        <div class="company-header d-flex justify-content-between align-items-start">
            <div>
                <div class="company-name">{{ config('app.name', 'Remote Center ERP') }}</div>
                @if ($challan->branch)
                    <div class="small text-muted">{{ $challan->branch->branch_name }}</div>
                @endif
            </div>
            <div class="text-end">
                <div class="doc-title">Delivery Challan</div>
                <div class="small text-muted">Page {{ $page }} of {{ $totalPages }}</div>
            </div>
        </div>

        {{-- Challan meta --}}
        <div class="meta-grid">
            <div>
                <div class="meta-label">Challan No.</div>
                <div class="meta-value">{{ $challan->challan_code }}</div>
            </div>
            <div class="text-end">
                <div class="meta-label">Date</div>
                <div class="meta-value">{{ \Carbon\Carbon::parse($challan->challan_date)->format('d M Y') }}</div>
            </div>
            <div>
                <div class="meta-label">Invoice No.</div>
                <div class="meta-value">{{ $challan->salesInvoice?->invoice_code ?? '—' }}</div>
            </div>
            <div>
                <div class="meta-label">Customer</div>
                <div class="meta-value">{{ $challan->salesInvoice?->customer?->customer_name ?? '—' }}</div>
            </div>
        </div>

        {{-- Transport details --}}
        <div class="meta-grid">
            <div>
                <div class="meta-label">Transport</div>
                <div class="meta-value">{{ $challan->transport_name ?? '—' }}</div>
            </div>
            <div>
                <div class="meta-label">Vehicle No.</div>
                <div class="meta-value">{{ $challan->vehicle_number ?? '—' }}</div>
            </div>
            <div>
                <div class="meta-label">Driver</div>
                <div class="meta-value">{{ $challan->driver_name ?? '—' }}</div>
            </div>
            <div>
                <div class="meta-label">Transport Phone</div>
                <div class="meta-value">{{ $challan->transport_phone ?? '—' }}</div>
            </div>
        </div>

        {{-- Items table --}}
        <table class="table table-sm table-bordered items-table">
            <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <th style="width:40%;">Product</th>
                    <th style="width:20%;">Warehouse</th>
                    <th class="text-end" style="width:12%;">Qty</th>
                    <th class="text-end" style="width:18%;">COGS (Tk)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pageItems as $item)
                    <tr>
                        <td>{{ ++$globalSl }}</td>
                        <td>{{ $item->product?->product_name ?? 'Product #' . $item->product_id }}</td>
                        <td>{{ $item->warehouse?->warehouse_name ?? '—' }}</td>
                        <td class="text-end">{{ number_format((float) $item->qty, 4) }}</td>
                        <td class="text-end">{{ number_format((float) $item->cogs_amount, 2) }}</td>
                    </tr>
                @endforeach
                @for ($i = $pageItems->count(); $i < $itemsPerPage && !$isLastPage; $i++)
                    <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
                @endfor
            </tbody>
        </table>

        {{-- Totals on last page --}}
        @if ($isLastPage)
            <div class="totals-section">
                <dl class="row mb-0">
                    <dt class="col-7">Transport Cost</dt>
                    <dd class="col-5">Tk {{ number_format((float) $challan->transport_cost, 2) }}</dd>

                    <dt class="col-7">Total Issue Cost (COGS)</dt>
                    <dd class="col-5"><strong>Tk {{ number_format((float) $challan->issue_cost, 2) }}</strong></dd>
                </dl>
            </div>

            {{-- Signatures --}}
            <div class="signature-section">
                <div class="signature-box">
                    <div class="small text-muted">Driver Signature</div>
                </div>
                <div class="signature-box">
                    <div class="small text-muted">Warehouse Manager</div>
                </div>
                <div class="signature-box">
                    <div class="small text-muted">Receiver Signature</div>
                </div>
            </div>
        @endif
    </div>
@endfor
@endsection
