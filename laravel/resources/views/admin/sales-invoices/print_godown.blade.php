@php $branchCode = $invoice->branch?->branch_code; @endphp
@extends('layouts.print')

@section('print_content')
@php
    try {
        $branchColorHex = \App\Support\BranchColor::hex($branchCode);
        $branchNameBn = \App\Support\BranchColor::get($branchCode)['name_bn'] ?? 'শাখা';
    } catch (\Throwable $e) {
        $branchColorHex = '#dc2626';
        $branchNameBn = 'শাখা';
    }
    $branchColorHex = $branchColorHex ?: '#dc2626';

    $totalQty = (float) $invoice->items->sum(fn($i) => (float) $i->qty);
    $subTotal = (float) $invoice->items->sum(fn($i) => (float) $i->amount);
    $transportCost = (float) ($invoice->transport_cost ?? 0);
    $grandTotal = (float) ($invoice->total_amount ?? 0);

    $dispatcherNames = [];
    if ($invoice->dispatchers && $invoice->dispatchers->count() > 0) {
        foreach ($invoice->dispatchers as $disp) {
            $dispatcherNames[] = $disp->name;
        }
    }
    $dispatcherLine = count($dispatcherNames) > 0 ? implode(', ', $dispatcherNames) : '—';
@endphp

<div class="print-page" style="position:relative;">
    <div style="position:relative; z-index:1;">
        {{-- Centered Header (branch-colored bottom border) --}}
        <div style="text-align:center; border-bottom:3px solid {{ $branchColorHex }}; padding-bottom:12px; margin-bottom:16px;">
            <div style="font-size:18px; font-weight:700;">RC DISTRIBUTION / আর সি বণিক</div>
            <div style="font-size:14px; color:#6b7280;">Godown Copy / গোডাউন কপি</div>
            <div style="font-size:11px; color:#9ca3af; margin-top:4px;">{{ $invoice->branch?->branch_name ?? '' }} ({{ strtoupper((string) $branchCode) }}) · {{ $branchNameBn }} শাখা</div>
        </div>

        {{-- Info Grid (2 cols) --}}
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; font-size:13px; margin-bottom:16px;">
            <div>
                <div style="margin-bottom:4px;"><strong>Invoice / চালান:</strong> {{ $invoice->invoice_code }}</div>
                <div style="margin-bottom:4px;"><strong>Date / তারিখ:</strong> {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d/m/Y') }}</div>
                <div><strong>Customer / ক্রেতা:</strong> {{ $invoice->customer?->customer_name ?? '—' }}@if ($invoice->customer?->customer_code) ({{ $invoice->customer->customer_code }})@endif</div>
            </div>
            <div>
                <div style="margin-bottom:4px;"><strong>Dispatchers / ডিসপ্যাচার:</strong> {{ $dispatcherLine }}</div>
                <div style="margin-bottom:4px;"><strong>Transport / পরিবহন:</strong> Tk {{ number_format($transportCost, 2) }}</div>
                <div><strong>Total / মোট:</strong> <span style="font-weight:700; color:{{ $branchColorHex }};">Tk {{ number_format($grandTotal, 2) }}</span></div>
            </div>
        </div>

        {{-- Items Table --}}
        <table style="width:100%; border-collapse:collapse; font-size:12px; margin-bottom:8px;">
            <thead>
                <tr style="background:{{ $branchColorHex }}22;">
                    <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:700; width:32px;">Sl</th>
                    <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:left; font-weight:700;">Product / পণ্য</th>
                    <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:700; width:90px;">Code</th>
                    <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:700; width:70px;">Demand / চাহিদা</th>
                    <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:700; width:90px;">Warehouse / গোডাউন</th>
                    <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:700; width:60px;">CTN / কার্টন</th>
                    <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:700; width:60px;">PCS / টুকরা</th>
                    <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:right; font-weight:700; width:70px;">Rate (Tk)</th>
                    <th style="border:1px solid #d1d5db; padding:6px 8px; text-align:right; font-weight:700; width:80px;">Amount (Tk)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->items as $idx => $item)
                    <tr>
                        <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:center;">{{ $idx + 1 }}</td>
                        <td style="border:1px solid #d1d5db; padding:6px 8px; font-weight:600;">{{ $item->product?->product_name ?? 'Product #' . $item->product_id }}</td>
                        <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:center;">{{ $item->product?->product_code ?? '—' }}</td>
                        <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:center; font-weight:600;">{{ number_format((float) $item->qty, 0) }}@if ($item->product?->unit) <span style="font-size:9px; color:#9ca3af;">{{ $item->product->unit }}</span>@endif</td>
                        <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:center;">
                            @if ($item->warehouse)
                                {{ $item->warehouse->warehouse_name }}
                            @else
                                <span style="color:#d97706;">Not assigned</span>
                            @endif
                        </td>
                        <td class="write-in" style="border:2px dashed #ccc; padding:6px 8px; text-align:center; background:#f5f5f5;">&nbsp;</td>
                        <td class="write-in" style="border:2px dashed #ccc; padding:6px 8px; text-align:center; background:#f5f5f5;">&nbsp;</td>
                        <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:right;">{{ number_format((float) $item->rate, 2) }}</td>
                        <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:right; font-weight:600;">{{ number_format((float) $item->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background:{{ $branchColorHex }}15; font-weight:700;">
                    <td style="border:1px solid #d1d5db; padding:6px 8px;" colspan="3">Total / মোট</td>
                    <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:center;">{{ number_format($totalQty, 0) }}</td>
                    <td style="border:1px solid #d1d5db; padding:6px 8px;">&nbsp;</td>
                    <td style="border:1px solid #d1d5db; padding:6px 8px;">&nbsp;</td>
                    <td style="border:1px solid #d1d5db; padding:6px 8px;">&nbsp;</td>
                    <td style="border:1px solid #d1d5db; padding:6px 8px;">&nbsp;</td>
                    <td style="border:1px solid #d1d5db; padding:6px 8px; text-align:right;">{{ number_format($subTotal, 2) }}</td>
                </tr>
            </tfoot>
        </table>

        {{-- Transport & Grand Total (2 cols) --}}
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:13px; margin-top:12px;">
            <div style="border:1px solid #d1d5db; border-radius:4px; padding:8px;"><strong>Transport Cost / পরিবহন:</strong> Tk {{ number_format($transportCost, 2) }}</div>
            <div style="border:2px solid {{ $branchColorHex }}; border-radius:4px; padding:8px; font-weight:700; color:{{ $branchColorHex }}; background:{{ $branchColorHex }}11;">Grand Total / সর্বমোট: Tk {{ number_format($grandTotal, 2) }}</div>
        </div>

        {{-- Instructions for warehouse staff --}}
        <div style="margin-top:12px; padding:10px; font-size:11px; background:{{ $branchColorHex }}15; border:1px dashed {{ $branchColorHex }}; border-radius:4px;">
            <strong>📋 নির্দেশনা / Instructions:</strong>
            প্রতিটি পণ্য ও পরিমাণ যাচাই করে নিন। CTN ও PCS কলাম হাতে পূরণ করুন এবং প্রতিটি লাইনে স্বাক্ষর করুন। লোডিং শেষে এই কপি অফিসে ফেরত দিন।<br>
            Verify each product + quantity before dispatch. Fill in CTN and PCS columns by hand and sign each line. Return this copy to the office after loading.
        </div>

        {{-- Signatures (3 cols, 160px each) --}}
        <div style="display:flex; justify-content:space-between; margin-top:40px;">
            <div style="text-align:center; width:160px;">
                <div style="height:40px;"></div>
                <div style="border-top:2px solid #9ca3af;"></div>
                <div style="font-size:12px; font-weight:600; margin-top:6px;">WM Signature</div>
                <div style="font-size:10px; color:#6b7280;">গোডাউন ম্যানেজার</div>
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
                <div style="font-size:12px; font-weight:600; margin-top:6px;">Received By</div>
                <div style="font-size:10px; color:#6b7280;">প্রাপক স্বাক্ষর</div>
            </div>
        </div>

        {{-- Footer --}}
        <div style="text-align:center; font-size:9px; color:#9ca3af; margin-top:12px; border-top:1px solid #e5e7eb; padding-top:4px;">
            Godown Copy · {{ $invoice->invoice_code }} · {{ $invoice->branch?->branch_name ?? '' }}
        </div>
    </div>
</div>
@endsection
