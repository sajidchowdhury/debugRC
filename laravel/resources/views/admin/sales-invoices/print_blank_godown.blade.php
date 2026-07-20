@extends('layouts.print')

@section('print_content')
{{-- Blank Godown Copy = handwriting template for manual picking --}}
{{-- Bengali/English bilingual labels, blank write-in cells, 17 items per page --}}

@php
    $allItems = $invoice->items;
    $itemsPerPage = 17;
    $itemPages = $allItems->chunk($itemsPerPage);
    $totalPages = $itemPages->count();
    $globalSl = 0;
@endphp

@foreach ($itemPages as $pageIndex => $pageItems)
<div class="print-page position-relative">
    {{-- BLANK GODOWN watermark --}}
    <div class="watermark" style="font-size:3.5rem; color:rgba(245,158,11,0.12);">BLANK GODOWN</div>

    {{-- Company header --}}
    <div class="company-header d-flex justify-content-between align-items-start">
        <div>
            <div class="company-name">{{ config('app.name', 'Remote Center ERP') }}</div>
            @if ($invoice->branch)
                <div class="small text-muted">{{ $invoice->branch->branch_name }}</div>
            @endif
        </div>
        <div class="text-end">
            <div class="doc-title" style="color:#d97706;">খালি গোডাউন / BLANK GODOWN</div>
            <div class="small text-muted">Manual Picking Sheet</div>
        </div>
    </div>

    {{-- Banner --}}
    <div class="mb-2 p-2 rounded" style="background:rgba(245,158,11,0.1); border:1px dashed #f59e0b;">
        <div class="d-flex justify-content-between align-items-center">
            <span class="fw-semibold small"><i class="fas fa-pen me-1"></i> ম্যানুয়াল পিকিং শিট — Handwrite warehouse, carton & pick confirmation</span>
            <span class="badge bg-warning text-dark">পৃষ্ঠা {{ $pageIndex + 1 }} / {{ $totalPages }}</span>
        </div>
        <div class="d-flex gap-3 small mt-1">
            <span>ইনভয়েস: <strong>{{ $invoice->invoice_code }}</strong></span>
            <span>মোট আইটেম: <strong>{{ $allItems->count() }}</strong></span>
        </div>
    </div>

    {{-- Invoice meta --}}
    <div class="meta-grid">
        <div>
            <div class="meta-label">ইনভয়েস নং / Invoice No.</div>
            <div class="meta-value">{{ $invoice->invoice_code }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">ইনভয়েস তারিখ / Date</div>
            <div class="meta-value">{{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') }}</div>
        </div>
        <div>
            <div class="meta-label">নাম / Customer</div>
            <div class="meta-value">{{ $invoice->customer?->customer_name ?? '—' }}</div>
        </div>
        <div class="text-end">
            <div class="meta-label">মোবাইল / Mobile</div>
            <div class="meta-value">{{ $invoice->customer?->mobile ?? $invoice->customer?->phone ?? '—' }}</div>
        </div>
        @if ($invoice->salesman)
        <div>
            <div class="meta-label">বিক্রয়কর্মী / Salesman</div>
            <div class="meta-value">{{ $invoice->salesman->name }}</div>
        </div>
        @endif
        <div class="text-end">
            <div class="meta-label">প্রিন্ট / Print</div>
            <div class="meta-value">{{ now()->format('d M Y') }}</div>
        </div>
    </div>

    {{-- Customer address block (first page only) --}}
    @if ($pageIndex === 0 && $invoice->customer)
    <div class="mb-2 p-2 border rounded small">
        <div class="row">
            <div class="col-2 text-muted">ঠিকানা / Address:</div>
            <div class="col-10">{{ $invoice->customer->address ?? '—' }}</div>
        </div>
        <div class="row">
            <div class="col-2 text-muted">শাখা / Branch:</div>
            <div class="col-10">{{ $invoice->branch?->branch_name ?? '—' }}</div>
        </div>
    </div>
    @endif

    {{-- Picking list table — BLANK writing cells --}}
    <table class="table table-sm table-bordered items-table">
        <thead style="background:#fef3c7;">
            <tr>
                <th style="width:5%;">ক্রম / #</th>
                <th style="width:30%;">পণ্যের নাম / Product</th>
                <th class="text-end" style="width:12%;">চাহিদা / Demand</th>
                <th class="text-center" style="width:18%;">গুদাম / Warehouse (লিখুন)</th>
                <th class="text-center" style="width:12%;">কার্টন / CTN</th>
                <th class="text-center" style="width:13%;">পিস তোলা / Picked</th>
                <th class="text-center" style="width:10%;">স্বাক্ষর / Sign</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($pageItems as $item)
                @php $globalSl++; @endphp
                <tr>
                    <td class="text-center">{{ $globalSl }}</td>
                    <td>
                        {{ $item->product?->product_name ?? 'Product #' . $item->product_id }}
                        @if ($item->product?->product_code)
                            <br><small class="text-muted">{{ $item->product->product_code }}</small>
                        @endif
                    </td>
                    <td class="text-end fw-semibold">{{ number_format((float) $item->qty, 2) }}</td>
                    {{-- Warehouse — blank write-in cell with hint --}}
                    <td class="text-center">
                        @if ($item->warehouse)
                            <span style="font-size:0.75rem; color:#999;">{{ $item->warehouse->warehouse_name }}</span>
                        @else
                            <span style="border-bottom:1px solid #ccc; display:inline-block; width:90%;">&nbsp;</span>
                        @endif
                    </td>
                    {{-- Carton — blank --}}
                    <td class="text-center">
                        <span style="border-bottom:1px solid #ccc; display:inline-block; width:70%;">&nbsp;</span>
                    </td>
                    {{-- Pieces picked — blank --}}
                    <td class="text-center">
                        <span style="border-bottom:1px solid #ccc; display:inline-block; width:70%;">&nbsp;</span>
                    </td>
                    {{-- Signature — blank --}}
                    <td class="text-center">
                        <span style="border-bottom:1px solid #ccc; display:inline-block; width:90%;">&nbsp;</span>
                    </td>
                </tr>
            @endforeach

            {{-- Fill remaining rows to keep consistent page height --}}
            @for ($i = $pageItems->count(); $i < $itemsPerPage; $i++)
                <tr style="height:28px;">
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                </tr>
            @endfor
        </tbody>
    </table>

    {{-- Last page: dispatcher write-in + signatures --}}
    @if ($pageIndex === $totalPages - 1)
        {{-- Summary --}}
        <div class="totals-section">
            <dl class="row mb-0">
                <dt class="col-7">মোট আইটেম / Total Items</dt>
                <dd class="col-5">{{ $allItems->count() }}</dd>
                <dt class="col-7">মোট পরিমাণ / Total Qty</dt>
                <dd class="col-5">{{ number_format($allItems->sum(fn($i) => (float) $i->qty), 2) }}</dd>
            </dl>
        </div>

        {{-- Dispatcher write-in block --}}
        <div class="mt-3 p-2 border rounded" style="background:rgba(245,158,11,0.05);">
            <div class="small fw-semibold mb-1">ডিসপ্যাচার / Dispatcher</div>
            @if ($invoice->dispatchers && $invoice->dispatchers->count() > 0)
                <div class="small mb-1">সিস্টেম: {{ $invoice->dispatchers->pluck('name')->join(', ') }}</div>
            @endif
            <div class="row g-1">
                <div class="col-6">
                    <div class="small text-muted">ডিসপ্যাচার নাম / Name</div>
                    <div style="border-bottom:1px solid #999; height:25px;">&nbsp;</div>
                </div>
                <div class="col-6">
                    <div class="small text-muted">তারিখ / সময় / Date & Time</div>
                    <div style="border-bottom:1px solid #999; height:25px;">&nbsp;</div>
                </div>
            </div>
        </div>

        {{-- Instructions --}}
        <div class="mt-2 p-2 rounded small" style="background:#fef9c3; border:1px dashed #eab308;">
            <i class="fas fa-triangle-exclamation me-1"></i>
            <strong>নির্দেশনা / Instructions:</strong>
            পিস তোলা অবশ্যই চাহিদার সমান হতে হবে। কম পিস দেওয়া যাবে না। কার্টন শুধু প্যাকিং।<br>
            Picked pieces must equal demand. Short delivery not allowed. Cartons are for packing only.
        </div>

        {{-- Signatures --}}
        <div class="signature-section mt-3">
            <div class="signature-box">
                <div class="small text-muted">ডিসপ্যাচার / Dispatcher</div>
            </div>
            <div class="signature-box">
                <div class="small text-muted">গোডাউন ম্যানেজার / Godown Manager</div>
            </div>
            <div class="signature-box">
                <div class="small text-muted">যাচাইকারী / Verified By</div>
            </div>
        </div>

        {{-- Footer --}}
        <div class="text-muted text-center mt-2" style="font-size:0.7rem;">
            Blank Godown · {{ now()->format('d-m-Y h:i A') }} · {{ $invoice->invoice_code }}
        </div>
    @else
        {{-- Continuation note on non-last pages --}}
        <p class="text-muted text-center small mt-2">… পরবর্তী পৃষ্ঠায় চালিয়ে যান / Continued on next page</p>
    @endif
</div>
@endforeach
@endsection
