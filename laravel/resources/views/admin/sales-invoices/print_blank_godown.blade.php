@php $branchCode = $invoice->branch?->branch_code; @endphp
@extends('layouts.print')

@section('print_content')
@php
    try {
        $branchColorHex = \App\Support\BranchColor::hex($branchCode);
        $branchColorName = \App\Support\BranchColor::get($branchCode)['color_name'] ?? 'Branch';
        $branchNameBn = \App\Support\BranchColor::get($branchCode)['name_bn'] ?? 'শাখা';
    } catch (\Throwable $e) {
        $branchColorHex = '#dc2626';
        $branchColorName = 'Red';
        $branchNameBn = 'শাখা';
    }
    $branchColorHex = $branchColorHex ?: '#dc2626';

    $allItems = $invoice->items;
    $itemsPerPage = 17;
    $itemPages = $allItems->chunk($itemsPerPage);
    $totalPages = $itemPages->count();
    $globalSl = 0;

    $totalQty = (float) $allItems->sum(fn($i) => (float) $i->qty);
    $subTotal = (float) ($invoice->sub_total ?? 0);
    $transportCost = (float) ($invoice->transport_cost ?? 0);
    $grandTotal = (float) ($invoice->total_amount ?? 0);
@endphp

@foreach ($itemPages as $pageIndex => $pageItems)
<div class="print-page" style="position:relative;">
    <div class="watermark">BLANK GODOWN</div>

    <div style="position:relative; z-index:1;">
        {{-- Branch Header --}}
        <div style="text-align:center; border-bottom:3px solid {{ $branchColorHex }}; padding-bottom:8px; margin-bottom:12px;">
            <div style="display:inline-flex; align-items:center; justify-content:center; gap:12px; vertical-align:middle;">
                <div style="display:inline-flex; align-items:center; justify-content:center; width:48px; height:48px; border-radius:8px; border:2px solid {{ $branchColorHex }}; background:{{ $branchColorHex }}22;">
                    <svg viewBox="0 0 40 40" width="30" height="30" style="display:block;">
                        <rect x="2" y="2" width="36" height="36" rx="6" fill="none" stroke="{{ $branchColorHex }}" stroke-width="3"></rect>
                        <text x="20" y="22" text-anchor="middle" font-size="13" font-weight="bold" fill="{{ $branchColorHex }}">{{ strtoupper((string) $branchCode) ?: 'RC' }}</text>
                        <line x1="8" y1="28" x2="32" y2="28" stroke="{{ $branchColorHex }}" stroke-width="2"></line>
                    </svg>
                </div>
                <div style="text-align:left;">
                    <div style="font-weight:700; font-size:18px; color:{{ $branchColorHex }};">{{ $invoice->branch?->branch_name ?? 'RC ERP' }} — {{ $branchColorName }} Branch</div>
                    <div style="font-size:13px; color:#6b7280;">RC DISTRIBUTION / আর সি বণিক</div>
                    <div style="font-size:11px; color:#9ca3af;">{{ $invoice->branch?->branch_name ?? '' }} / {{ $branchNameBn }} শাখা</div>
                </div>
            </div>
        </div>

        {{-- Bilingual Title Box --}}
        <div style="text-align:center; font-weight:700; padding:6px 0; margin-bottom:10px; border:2px solid {{ $branchColorHex }}; color:{{ $branchColorHex }};">
            <div style="font-size:18px;">খালি গোডাউন কপি</div>
            <div style="font-size:12px;">BLANK GODOWN COPY · Manual Picking Sheet</div>
        </div>

        {{-- Customer & Invoice Info Grid (2-col, bilingual bold labels) --}}
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:4px 16px; margin-bottom:12px; font-size:13px;">
            <div><span style="font-weight:700; color:#6b7280;">ক্রেতা / Customer:</span> {{ $invoice->customer?->customer_name ?? '—' }}@if ($invoice->customer?->customer_code) ({{ $invoice->customer->customer_code }})@endif</div>
            <div><span style="font-weight:700; color:#6b7280;">শাখা / Branch:</span> {{ $invoice->branch?->branch_name ?? '—' }} ({{ strtoupper((string) $branchCode) }})</div>
            <div><span style="font-weight:700; color:#6b7280;">মোবাইল / Mobile:</span> {{ $invoice->customer?->mobile ?? $invoice->customer?->phone ?? '—' }}</div>
            <div><span style="font-weight:700; color:#6b7280;">চালান / Invoice:</span> {{ $invoice->invoice_code }}</div>
            <div><span style="font-weight:700; color:#6b7280;">ঠিকানা / Address:</span> {{ $invoice->customer?->address ?? '—' }}</div>
            <div><span style="font-weight:700; color:#6b7280;">তারিখ / Date:</span> {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d/m/Y') }}</div>
            @if ($invoice->salesman)
                <div><span style="font-weight:700; color:#6b7280;">বিক্রেতা / Salesman:</span> {{ $invoice->salesman->name }}</div>
            @endif
            <div><span style="font-weight:700; color:#6b7280;">মোট / Total:</span> <span style="font-weight:700; color:{{ $branchColorHex }};">Tk {{ number_format($grandTotal, 2) }}</span></div>
        </div>

        {{-- Items Table with write-in cells --}}
        <table style="width:100%; border-collapse:collapse; font-size:12px; margin-bottom:8px;">
            <thead>
                <tr style="background:{{ $branchColorHex }}22;">
                    <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:700; width:32px;">ক্রম<br><span style="font-size:9px;">Sl</span></th>
                    <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:left; font-weight:700; min-width:120px;">পণ্যের নাম<br><span style="font-size:9px;">Product Name</span></th>
                    <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:700; width:60px;">চাহিদা<br><span style="font-size:9px;">Demand</span></th>
                    <th class="write-in" style="border:2px dashed #999; padding:6px 8px; text-align:center; font-weight:700; width:90px;">গুদাম<br><span style="font-size:9px;">Warehouse (লিখুন)</span></th>
                    <th class="write-in" style="border:2px dashed #999; padding:6px 8px; text-align:center; font-weight:700; width:60px;">কার্টন<br><span style="font-size:9px;">CTN</span></th>
                    <th class="write-in" style="border:2px dashed #999; padding:6px 8px; text-align:center; font-weight:700; width:70px;">পিস তোলা<br><span style="font-size:9px;">Picked</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pageItems as $item)
                    @php $globalSl++; @endphp
                    <tr>
                        <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:600;">{{ $globalSl }}</td>
                        <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:left;">
                            <div style="font-weight:600;">{{ $item->product?->product_name ?? 'Product #' . $item->product_id }}</div>
                            @if ($item->product?->product_code || $item->product?->unit)
                                <div style="font-size:9px; color:#6b7280;">{{ $item->product?->product_code ?? '' }}@if ($item->product?->unit) @if ($item->product?->product_code) • @endif {{ $item->product->unit }}@endif</div>
                            @endif
                        </td>
                        <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:700;">{{ number_format((float) $item->qty, 0) }}</td>
                        <td class="write-in" style="border:2px dashed #ccc; padding:6px 8px; text-align:center; background:#f5f5f5;">
                            @if ($item->warehouse)
                                <span style="font-size:10px; color:#9ca3af;">{{ $item->warehouse->warehouse_name }}</span>
                            @else
                                &nbsp;<span style="font-size:8px; color:#aaa; float:right; text-decoration:underline dashed;">(লিখুন)</span>
                            @endif
                        </td>
                        <td class="write-in" style="border:2px dashed #ccc; padding:6px 8px; text-align:center; background:#f5f5f5;">&nbsp;</td>
                        <td class="write-in" style="border:2px dashed #ccc; padding:6px 8px; text-align:center; background:#f5f5f5;">&nbsp;</td>
                    </tr>
                @endforeach

                {{-- Fill remaining rows to 17 --}}
                @for ($i = $pageItems->count(); $i < $itemsPerPage; $i++)
                    <tr style="height:26px;">
                        <td style="border:1px solid #d1d5db; padding:4px 8px;">&nbsp;</td>
                        <td style="border:1px solid #d1d5db; padding:4px 8px;">&nbsp;</td>
                        <td style="border:1px solid #d1d5db; padding:4px 8px;">&nbsp;</td>
                        <td class="write-in" style="border:2px dashed #ccc; padding:4px 8px; background:#f5f5f5;">&nbsp;</td>
                        <td class="write-in" style="border:2px dashed #ccc; padding:4px 8px; background:#f5f5f5;">&nbsp;</td>
                        <td class="write-in" style="border:2px dashed #ccc; padding:4px 8px; background:#f5f5f5;">&nbsp;</td>
                    </tr>
                @endfor

                {{-- Total row (always on every page) --}}
                <tr style="background:{{ $branchColorHex }}15; font-weight:700;">
                    <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:center;" colspan="2">মোট / Total</td>
                    <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:700;">{{ number_format($totalQty, 0) }}</td>
                    <td class="write-in" style="border:2px dashed #ccc; padding:6px 8px; background:#f5f5f5;">&nbsp;</td>
                    <td class="write-in" style="border:2px dashed #ccc; padding:6px 8px; text-align:center; background:#f5f5f5;">&nbsp;</td>
                    <td class="write-in" style="border:2px dashed #ccc; padding:6px 8px; background:#f5f5f5;">&nbsp;</td>
                </tr>
            </tbody>
        </table>

        @if ($pageIndex === $totalPages - 1)
            {{-- Amount Summary (3 cols) --}}
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; font-size:13px; margin:8px 0;">
                <div style="border:1px solid #d1d5db; border-radius:4px; padding:8px;"><span style="font-weight:700; color:#6b7280;">Subtotal / সাবটোটাল:</span> Tk {{ number_format($subTotal, 2) }}</div>
                <div style="border:1px solid #d1d5db; border-radius:4px; padding:8px;"><span style="font-weight:700; color:#6b7280;">Transport / পরিবহন:</span> Tk {{ number_format($transportCost, 2) }}</div>
                <div style="border:2px solid {{ $branchColorHex }}; border-radius:4px; padding:8px; font-weight:700; color:{{ $branchColorHex }};">Total / মোট: Tk {{ number_format($grandTotal, 2) }}</div>
            </div>

            {{-- Dispatcher Block --}}
            <div style="border:1px solid #d1d5db; padding:10px; background:#f9fafb; margin-bottom:8px;">
                <div style="font-weight:700; font-size:13px; margin-bottom:4px; color:{{ $branchColorHex }};">ডিসপ্যাচার / Dispatchers</div>
                @if ($invoice->dispatchers && $invoice->dispatchers->count() > 0)
                    <div style="font-size:12px; color:#374151; margin-bottom:4px;">
                        @foreach ($invoice->dispatchers as $disp)
                            <span style="margin-right:10px;">{{ $disp->name }}@if ($disp->phone) · {{ $disp->phone }}@endif</span>
                        @endforeach
                    </div>
                @else
                    <div style="font-size:12px; color:#9ca3af; margin-bottom:4px;">— নির্বাচিত নয় / Not assigned —</div>
                @endif
                <div style="font-size:12px; color:#6b7280; margin-bottom:6px;"><span style="font-weight:700;">WM নির্দেশনা / Instructions:</span> প্রথমে গোডাউন-৩ যান, তারপর গোডাউন-১</div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:4px;">অতিরিক্ত নোট / Additional Notes:</div>
                <div style="border:2px dashed #9ca3af; background:white; min-height:36px; padding:4px;">&nbsp;</div>
            </div>

            {{-- Instructions Box (branch-colored) --}}
            <div style="border:2px solid {{ $branchColorHex }}; padding:10px; margin-bottom:8px; background:{{ $branchColorHex }}11;">
                <div style="font-weight:700; font-size:13px; color:{{ $branchColorHex }};">📋 নির্দেশনা / Instructions</div>
                <div style="font-size:11px; margin-top:4px; color:{{ $branchColorHex }}cc;">
                    <p style="margin:0 0 4px 0;">ডিসপ্যাচার: প্রতিটি পণ্যের জন্য গুদামের নাম (Warehouse Name) ও কার্টন সংখ্যা (CTN) এবং পিস তোলা (Picked) হাতে লিখে পূরণ করুন। পূরণ হওয়া কপি Warehouse Manager এর কাছে ফিরিয়ে দিতে হবে।</p>
                    <p style="margin:0;">Dispatcher: Fill in the Warehouse Name, CTN, and Picked columns for each product by hand. The filled copy must be returned to the Warehouse Manager.</p>
                </div>
            </div>

            {{-- Signatures (3 columns, 140px each, 40px top margin line) --}}
            <div style="display:flex; justify-content:space-between; margin-top:32px;">
                <div style="text-align:center; width:140px;">
                    <div style="height:40px;"></div>
                    <div style="border-top:1px solid #000;"></div>
                    <div style="font-size:11px; font-weight:600; margin-top:4px;">ডিসপ্যাচার / Dispatcher</div>
                </div>
                <div style="text-align:center; width:140px;">
                    <div style="height:40px;"></div>
                    <div style="border-top:1px solid #000;"></div>
                    <div style="font-size:11px; font-weight:600; margin-top:4px;">গোডাউন ম্যানেজার / Godown Manager</div>
                </div>
                <div style="text-align:center; width:140px;">
                    <div style="height:40px;"></div>
                    <div style="border-top:1px solid #000;"></div>
                    <div style="font-size:11px; font-weight:600; margin-top:4px;">যাচাইকারী / Verifier</div>
                </div>
            </div>

            {{-- Footer --}}
            <div style="text-align:center; font-size:9px; color:#9ca3af; margin-top:8px; border-top:1px solid #e5e7eb; padding-top:4px;">
                Page {{ $pageIndex + 1 }} of {{ $totalPages }} · {{ $invoice->invoice_code }} · {{ $invoice->branch?->branch_name ?? '' }}
            </div>
        @else
            {{-- Continuation note on non-last pages --}}
            <div style="text-align:center; font-size:11px; color:#9ca3af; margin-top:8px; border-top:1px solid #e5e7eb; padding-top:4px;">
                Page {{ $pageIndex + 1 }} of {{ $totalPages }} · {{ $invoice->invoice_code }} · … পরবর্তী পৃষ্ঠায় চালিয়ে যান / Continued on next page
            </div>
        @endif
    </div>
</div>
@endforeach
@endsection
