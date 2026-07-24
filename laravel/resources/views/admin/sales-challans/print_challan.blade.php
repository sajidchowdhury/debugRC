@php $branchCode = $challan->branch?->branch_code; @endphp
@extends('layouts.print')

@section('print_content')
@php
    $branchColorHex = \App\Support\BranchColor::hex($branchCode);
    $branchNameBn = \App\Support\BranchColor::get($branchCode)['name_bn'] ?? 'শাখা';

    $itemsPerPage = 17;
    $allItems = $challan->items ?? collect();
    $totalPages = max(1, ceil($allItems->count() / $itemsPerPage));
    $globalSl = 0;

    $totalCogs = (float) ($allItems->sum(fn($i) => (float) $i->cogs_amount) ?? 0);
    $transportCost = (float) ($challan->transport_cost ?? 0);
    $issueCost = (float) ($challan->issue_cost ?? $totalCogs);
    $invoiceTotal = (float) ($challan->salesInvoice?->total_amount ?? 0);

    $customer = $challan->salesInvoice?->customer;
@endphp

@for ($page = 1; $page <= $totalPages; $page++)
    @php
        $pageItems = $allItems->slice(($page - 1) * $itemsPerPage, $itemsPerPage);
        $isLastPage = ($page === $totalPages);
    @endphp

    <div class="print-page" style="position:relative;">
        @if ($challan->is_reversed)
            <div class="watermark" style="color:rgba(239,68,68,0.15);">CANCELLED</div>
        @endif

        <div style="position:relative; z-index:1;">
            {{-- Centered Header --}}
            <div style="text-align:center; border-bottom:3px solid {{ $branchColorHex }}; padding-bottom:12px; margin-bottom:16px;">
                <div style="font-size:18px; font-weight:700;">RC DISTRIBUTION / আর সি বণিক</div>
                <div style="font-size:14px; color:#6b7280;">Delivery Challan / চালানপত্র (ডেলিভারি)</div>
                <div style="font-size:11px; color:#9ca3af; margin-top:4px;">{{ $challan->branch?->branch_name ?? '' }} ({{ strtoupper((string) $branchCode) }}) · {{ $branchNameBn }} শাখা</div>
                <div style="font-size:15px; font-weight:700; color:{{ $branchColorHex }}; margin-top:6px;">{{ $challan->challan_code }}</div>
                @if ($totalPages > 1)
                    <div style="font-size:10px; color:#9ca3af; margin-top:2px;">Page {{ $page }} of {{ $totalPages }}</div>
                @endif
            </div>

            @if ($isLastPage)
                {{-- Invoice & Customer Info (2 cols) --}}
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; font-size:13px; margin-bottom:16px;">
                    <div>
                        <div style="margin-bottom:4px;"><strong>Challan No / চালান নং:</strong> {{ $challan->challan_code }}</div>
                        <div style="margin-bottom:4px;"><strong>Challan Date / তারিখ:</strong> {{ \Carbon\Carbon::parse($challan->challan_date)->format('d/m/Y') }}</div>
                        <div style="margin-bottom:4px;"><strong>Invoice Ref / চালান রেফ:</strong> {{ $challan->salesInvoice?->invoice_code ?? '—' }}</div>
                        <div><strong>Dispatchers / ডিসপ্যাচার:</strong>
                            @if ($challan->salesInvoice && $challan->salesInvoice->dispatchers && $challan->salesInvoice->dispatchers->count() > 0)
                                {{ $challan->salesInvoice->dispatchers->pluck('name')->join(', ') }}
                            @else
                                —
                            @endif
                        </div>
                    </div>
                    <div>
                        <div style="margin-bottom:4px;"><strong>Customer / ক্রেতা:</strong> {{ $customer?->customer_name ?? '—' }}</div>
                        <div style="margin-bottom:4px;"><strong>Phone / ফোন:</strong> {{ $customer?->mobile ?? $customer?->phone ?? '—' }}</div>
                        <div style="margin-bottom:4px;"><strong>Address / ঠিকানা:</strong> {{ $customer?->address ?? '—' }}</div>
                        <div><strong>Total Amount / মোট:</strong> <span style="font-weight:700; color:{{ $branchColorHex }};">Tk {{ number_format($invoiceTotal, 2) }}</span></div>
                    </div>
                </div>

                {{-- Transport Box (branch-tinted bg + border) --}}
                <div style="background:{{ $branchColorHex }}11; border:2px solid {{ $branchColorHex }}55; border-radius:4px; padding:12px; margin-bottom:16px;">
                    <div style="font-weight:700; font-size:13px; margin-bottom:8px;">Transport Details / পরিবহন তথ্য</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; font-size:12px;">
                        <div><strong>Transport / পরিবহন:</strong> {{ $challan->transport_name ?? '—' }}</div>
                        <div><strong>Vehicle / গাড়ি:</strong> {{ $challan->vehicle_number ?? '—' }}</div>
                        <div><strong>Driver / চালক:</strong> {{ $challan->driver_name ?? '—' }}</div>
                    </div>
                    <div style="margin-top:8px; font-size:12px;">
                        <strong>Transport Phone / ফোন:</strong> {{ $challan->transport_phone ?? '—' }}
                        &nbsp;·&nbsp;
                        <strong>Transport Cost / পরিবহন খরচ:</strong> Tk {{ number_format($transportCost, 2) }}
                    </div>
                </div>
            @else
                {{-- Continuation page — minimal info --}}
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; font-size:13px; margin-bottom:16px;">
                    <div><strong>Challan No / চালান নং:</strong> {{ $challan->challan_code }}</div>
                    <div><strong>Invoice Ref / চালান রেফ:</strong> {{ $challan->salesInvoice?->invoice_code ?? '—' }}</div>
                    <div><strong>Date / তারিখ:</strong> {{ \Carbon\Carbon::parse($challan->challan_date)->format('d/m/Y') }}</div>
                    <div><strong>Customer / ক্রেতা:</strong> {{ $customer?->customer_name ?? '—' }}</div>
                </div>
            @endif

            {{-- Items Table --}}
            <table style="width:100%; border-collapse:collapse; font-size:12px; margin-bottom:8px;">
                <thead>
                    <tr style="background:{{ $branchColorHex }}22;">
                        <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:700; width:32px;">Sl</th>
                        <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:left; font-weight:700;">Product / পণ্য</th>
                        <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:700; width:100px;">Code</th>
                        <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:700; width:100px;">Warehouse / গোডাউন</th>
                        <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:700; width:70px;">Qty / পরিমাণ</th>
                        <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:right; font-weight:700; width:80px;">Rate (Tk)</th>
                        <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:right; font-weight:700; width:90px;">COGS (Tk)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pageItems as $item)
                        <tr>
                            <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:center;">{{ ++$globalSl }}</td>
                            <td style="border:1px solid #d1d5db; padding:6px 8px; font-weight:600;">{{ $item->product?->product_name ?? 'Product #' . $item->product_id }}</td>
                            <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:center;">{{ $item->product?->product_code ?? '—' }}</td>
                            <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:center;">{{ $item->warehouse?->warehouse_name ?? '—' }}</td>
                            <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:center;">{{ number_format((float) $item->qty, 0) }}@if ($item->product?->unit) <span style="font-size:9px; color:#9ca3af;">{{ $item->product->unit }}</span>@endif</td>
                            <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:right;">{{ number_format((float) ($item->issue_rate ?? 0), 2) }}</td>
                            <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:right; font-weight:600;">{{ number_format((float) $item->cogs_amount, 2) }}</td>
                        </tr>
                    @endforeach

                    @for ($i = $pageItems->count(); $i < $itemsPerPage && !$isLastPage; $i++)
                        <tr style="height:24px;">
                            <td style="border:1px solid #d1d5db; padding:4px 8px;">&nbsp;</td>
                            <td style="border:1px solid #d1d5db; padding:4px 8px;">&nbsp;</td>
                            <td style="border:1px solid #d1d5db; padding:4px 8px;">&nbsp;</td>
                            <td style="border:1px solid #d1d5db; padding:4px 8px;">&nbsp;</td>
                            <td style="border:1px solid #d1d5db; padding:4px 8px;">&nbsp;</td>
                            <td style="border:1px solid #d1d5db; padding:4px 8px;">&nbsp;</td>
                            <td style="border:1px solid #d1d5db; padding:4px 8px;">&nbsp;</td>
                        </tr>
                    @endfor
                </tbody>
                @if ($isLastPage)
                    <tfoot>
                        <tr style="background:{{ $branchColorHex }}15; font-weight:700;">
                            <td style="border:1px solid #d1d5db; padding:6px 8px;" colspan="5">Total COGS / মোট ক্রয়মূল্য</td>
                            <td style="border:1px solid #d1d5db; padding:6px 8px;">&nbsp;</td>
                            <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:right;">{{ number_format($totalCogs, 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>

            @if ($isLastPage)
                {{-- Grand Total Box (branch-tinted) --}}
                <div style="background:{{ $branchColorHex }}22; border:2px solid {{ $branchColorHex }}; border-radius:4px; padding:12px; font-size:13px; margin-top:12px;">
                    <div style="display:flex; justify-content:space-between; gap:16px;">
                        <div><strong>Sales Total / বিক্রয় মোট:</strong> Tk {{ number_format($invoiceTotal, 2) }}</div>
                        <div><strong>COGS / ক্রয়মূল্য:</strong> Tk {{ number_format($issueCost, 2) }}</div>
                        <div><strong>Transport / পরিবহন:</strong> Tk {{ number_format($transportCost, 2) }}</div>
                    </div>
                </div>

                {{-- Terms text (bilingual) --}}
                <div style="font-size:11px; color:#6b7280; margin-top:10px;">
                    <p style="margin:0 0 2px 0;">সতর্কতা: এই চালানপত্র পণ্য প্রাপকের স্বাক্ষর বিহীন অবস্থায় গ্রহণযোগ্য নয়।</p>
                    <p style="margin:0;">Note: This challan is not valid without the receiver's signature. Please verify all items before signing.</p>
                </div>

                {{-- Signatures (3 cols, 160px each) --}}
                <div style="display:flex; justify-content:space-between; margin-top:40px;">
                    <div style="text-align:center; width:160px;">
                        <div style="height:40px;"></div>
                        <div style="border-top:2px solid #9ca3af;"></div>
                        <div style="font-size:12px; font-weight:600; margin-top:6px;">Authorized By</div>
                        <div style="font-size:10px; color:#6b7280;">অনুমোদনকারী</div>
                    </div>
                    <div style="text-align:center; width:160px;">
                        <div style="height:40px;"></div>
                        <div style="border-top:2px solid #9ca3af;"></div>
                        <div style="font-size:12px; font-weight:600; margin-top:6px;">Dispatcher</div>
                        <div style="font-size:10px; color:#6b7280;">ডিসপ্যাচার</div>
                    </div>
                    <div style="text-align:center; width:160px;">
                        <div style="height:40px;"></div>
                        <div style="border-top:2px solid #9ca3af;"></div>
                        <div style="font-size:12px; font-weight:600; margin-top:6px;">Received By (Customer)</div>
                        <div style="font-size:10px; color:#6b7280;">প্রাপক (ক্রেতা)</div>
                    </div>
                </div>

                {{-- Company Seal Stamp Box --}}
                <div style="margin-top:24px; text-align:center;">
                    <div style="border:2px dashed #d1d5db; border-radius:8px; padding:16px; width:200px; margin:0 auto;">
                        <div style="font-size:11px; color:#9ca3af;">Company Seal / কোম্পানি সিল</div>
                    </div>
                </div>

                {{-- Footer --}}
                <div style="text-align:center; font-size:9px; color:#9ca3af; margin-top:12px; border-top:1px solid #e5e7eb; padding-top:4px;">
                    Delivery Challan · {{ $challan->challan_code }} · {{ $challan->branch?->branch_name ?? '' }}
                </div>
            @else
                {{-- Continuation footer --}}
                <div style="text-align:center; font-size:9px; color:#9ca3af; margin-top:12px; border-top:1px solid #e5e7eb; padding-top:4px;">
                    Page {{ $page }} of {{ $totalPages }} · {{ $challan->challan_code }} · … পরবর্তী পৃষ্ঠায় চালিয়ে যান / Continued on next page
                </div>
            @endif
        </div>
    </div>
@endfor
@endsection
