{{--
    print_invoice.blade.php — Branch-wise invoice PDF matching the exact layout
    from the attached reference invoices (Bengali/English bilingual).

    Key design specs (from reference PDF):
    - A4 portrait, 17 items per page
    - Header repeats on every page (branch-specific image or text)
    - 6-column product table: ক্র নং | পদার্থের নাম | পরিমাণ | একক | দর | টাকা
    - Totals section on last page inside the table grid
    - Terms & conditions, signatures at bottom
    - Footer with page numbers on every page

    This view is designed for DomPDF (barryvdh/laravel-dompdf) rendering.
    It uses inline styles for maximum PDF compatibility.
--}}
@php
    $itemsPerPage = 17;
    $allItems = $invoice->items;
    $totalPages = max(1, ceil($allItems->count() / $itemsPerPage));
    $globalSl = 0;
    $branch = $invoice->branch;
    $customer = $invoice->customer;

    // Bengali numeral conversion
    $bengaliNum = function($num) {
        $bnDigits = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
        $str = (string) $num;
        $result = '';
        for ($i = 0; $i < strlen($str); $i++) {
            $c = $str[$i];
            $result .= ($c >= '0' && $c <= '9') ? $bnDigits[(int)$c] : $c;
        }
        return $result;
    };

    // Number to Bengali words (simplified — for invoice total)
    $bengaliWords = function($amount) use ($bengaliNum) {
        if ($amount < 0) return '';
        $tk = (int) floor($amount);
        $paisa = round(($amount - $tk) * 100);
        // Simplified Bengali amount text
        $text = 'মাত্র ' . $bengaliNum(number_format($tk, 0, '', '')) . ' টাকা';
        if ($paisa > 0) {
            $text .= ' ও ' . $bengaliNum(str_pad($paisa, 2, '0', STR_PAD_LEFT)) . ' পয়সা';
        }
        $text .= ' মাত্র';
        return $text;
    };

    $invoiceDate = \Carbon\Carbon::parse($invoice->invoice_date);

    // Branch invoice settings
    $isPdfMode = request()->input('mode') === 'pdf';
    $headerImageDbPath = $branch?->invoice_header_image; // relative path e.g. "branch-invoices/xxx.png"
    $footerImageDbPath = $branch?->invoice_footer_image;

    // For browser: use public URL; for DomPDF: use absolute filesystem path
    if ($headerImageDbPath) {
        $headerImage = $isPdfMode
            ? Storage::disk('public')->path($headerImageDbPath)
            : Storage::disk('public')->url($headerImageDbPath);
    } else {
        $headerImage = null;
    }
    if ($footerImageDbPath) {
        $footerImage = $isPdfMode
            ? Storage::disk('public')->path($footerImageDbPath)
            : Storage::disk('public')->url($footerImageDbPath);
    } else {
        $footerImage = null;
    }

    $headerText = $branch?->invoice_header_text;
    $footerText = $branch?->invoice_footer_text;
    $watermarkText = $branch?->invoice_watermark_text ?? ($branch?->company?->company_name ?? '');
    $signatoryName = $branch?->invoice_signatory_name;
    $signatoryTitle = $branch?->invoice_signatory_title;
    $termsText = $branch?->invoice_terms;

    // Check if header/footer image exists on disk (for both browser and PDF mode)
    $headerImagePath = $headerImageDbPath ? Storage::disk('public')->path($headerImageDbPath) : null;
    $footerImagePath = $footerImageDbPath ? Storage::disk('public')->path($footerImageDbPath) : null;
    $hasHeaderImage = !empty($headerImage) && $headerImagePath && file_exists($headerImagePath);
    $hasFooterImage = !empty($footerImage) && $footerImagePath && file_exists($footerImagePath);
@endphp

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->invoice_code }}</title>
    <style>
        /* === A4 page setup for DomPDF === */
        @page {
            size: A4 portrait;
            margin: 10mm 12mm 10mm 12mm;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Noto Sans SC', 'Noto Sans Bengali', 'SolaimanLipi', 'Arial', sans-serif;
            font-size: 13px;
            line-height: 1.45;
            color: #000;
            margin: 0;
            padding: 0;
        }

        /* === Watermark === */
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 60px;
            font-weight: bold;
            color: rgba(200, 0, 0, 0.06);
            white-space: nowrap;
            z-index: 0;
            pointer-events: none;
        }
        .watermark-cancelled {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-25deg);
            font-size: 80px;
            font-weight: bold;
            color: rgba(220, 38, 38, 0.15);
            white-space: nowrap;
            z-index: 100;
            pointer-events: none;
            border: 4px solid rgba(220, 38, 38, 0.15);
            padding: 10px 40px;
        }

        /* === Header section === */
        .invoice-header {
            width: 100%;
            margin-bottom: 6px;
        }
        .invoice-header img {
            width: 100%;
            max-height: 180px;
            object-fit: contain;
        }
        .header-text-section {
            padding: 4px 0;
            font-size: 12px;
        }

        /* === Metadata fields (bordered boxes) === */
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .meta-table td {
            border: 1px solid #000;
            padding: 4px 7px;
            font-size: 13px;
            vertical-align: top;
        }
        .meta-table .meta-label {
            font-weight: bold;
            white-space: nowrap;
            padding-right: 4px;
        }
        .meta-table .meta-value {
            font-weight: normal;
        }

        /* === Product table === */
        .product-table {
            width: 100%;
            border-collapse: collapse;
        }
        .product-table th {
            border: 1px solid #000;
            padding: 5px 6px;
            font-weight: bold;
            font-size: 13px;
            text-align: center;
            background: #f5f5f5;
        }
        .product-table th.col-product {
            text-align: left;
        }
        .product-table td {
            border: 1px solid #000;
            padding: 4px 6px;
            font-size: 13px;
        }
        .product-table td.col-sl {
            text-align: center;
        }
        .product-table td.col-qty {
            text-align: right;
        }
        .product-table td.col-unit {
            text-align: center;
        }
        .product-table td.col-rate {
            text-align: right;
        }
        .product-table td.col-amount {
            text-align: right;
            font-weight: bold;
        }
        /* Totals rows inside the table */
        .product-table tr.total-row td {
            font-weight: bold;
            border: 1px solid #000;
        }
        .product-table tr.total-row-grand td {
            font-weight: bold;
            font-size: 14px;
            border: 1px solid #000;
        }
        .product-table .total-label {
            text-align: right;
            padding-right: 8px;
        }
        .product-table .total-value {
            text-align: right;
            font-weight: bold;
        }

        /* === Terms section === */
        .terms-section {
            margin-top: 8px;
            font-size: 11px;
            line-height: 1.5;
        }
        .terms-section .terms-title {
            font-weight: bold;
            margin-bottom: 2px;
        }

        /* === Signatures === */
        .signature-section {
            margin-top: 25px;
            width: 100%;
            display: flex;
            justify-content: space-between;
        }
        .signature-box {
            width: 45%;
        }
        .signature-line {
            border-top: 1px dashed #000;
            margin-bottom: 3px;
            padding-top: 20px;
        }
        .signature-label {
            font-size: 12px;
            text-align: center;
        }
        .signature-name {
            font-size: 12px;
            text-align: center;
            font-weight: bold;
        }

        /* === Footer === */
        .invoice-footer {
            margin-top: 8px;
            width: 100%;
        }
        .invoice-footer img {
            width: 100%;
            max-height: 120px;
            object-fit: contain;
        }
        .footer-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 4px;
            font-size: 10px;
            color: #555;
            border-top: 1px solid #ccc;
            padding-top: 4px;
        }
        .footer-text {
            flex: 1;
            text-align: center;
            font-size: 10px;
        }
        .footer-page {
            text-align: right;
            font-size: 11px;
            white-space: nowrap;
        }

        /* === Page break === */
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    @if ($watermarkText)
        <div class="watermark">{{ e($watermarkText) }}</div>
    @endif
    @if ($invoice->is_reversed)
        <div class="watermark-cancelled">CANCELLED</div>
    @endif

    @for ($page = 1; $page <= $totalPages; $page++)
        @php
            $pageItems = $allItems->slice(($page - 1) * $itemsPerPage, $itemsPerPage);
            $isLastPage = ($page === $totalPages);
        @endphp

        {{-- ===== PAGE HEADER (repeats on every page) ===== --}}
        <div class="invoice-header">
            @if ($hasHeaderImage)
                <img src="{{ $headerImage }}" alt="Invoice Header" style="width:100%; max-height:180px; object-fit:contain; display:block; margin:0 auto;">
            @else
                {{-- Fallback: text-based header when no image uploaded --}}
                <table style="width:100%; border-collapse:collapse; border-bottom:2px solid #b91c1c; padding-bottom:4px; margin-bottom:4px;">
                    <tr>
                        <td style="width:18%; vertical-align:middle; padding:4px 6px;">
                            {{-- Logo placeholder --}}
                            <div style="font-size:22px; font-weight:bold; color:#dc2626;">&#9733; STAR</div>
                        </td>
                        <td style="width:52%; text-align:center; vertical-align:middle; padding:4px 2px;">
                            <div style="font-size:12px;">সেলফোন: {{ e($branch?->phone ?? '') }}</div>
                            <div style="font-size:24px; font-weight:bold; color:#e91e63; margin:2px 0;">রিমোট সেন্টার</div>
                            <div style="font-size:16px; font-weight:bold; color:#1565c0; letter-spacing:2px;">REMOTE CENTER</div>
                            <div style="font-size:11px; margin-top:2px;">{{ e($branch?->address ?? $branch?->branch_name ?? '') }}</div>
                        </td>
                        <td style="width:30%; text-align:right; vertical-align:middle; padding:4px 6px; font-size:12px; line-height:1.6;">
                            @if ($branch?->phone)
                                <div>{{ e($branch->phone) }}</div>
                            @endif
                            @if ($branch?->email)
                                <div>{{ e($branch->email) }}</div>
                            @endif
                        </td>
                    </tr>
                </table>
            @endif
            @if ($headerText)
                <div class="header-text-section">{!! $headerText !!}</div>
            @endif
        </div>

        {{-- ===== INVOICE METADATA (bordered boxes) ===== --}}
        <table class="meta-table">
            <tr>
                <td style="width:15%;"><span class="meta-label">ইনভয়েস নং:</span></td>
                <td style="width:35%;"><span class="meta-value">{{ e($invoice->invoice_code) }}</span></td>
                <td style="width:15%;"><span class="meta-label">তারিখ:</span></td>
                <td style="width:35%;"><span class="meta-value">{{ $invoiceDate->format('d-m-Y') }}</span></td>
            </tr>
            <tr>
                <td><span class="meta-label">নাম:</span></td>
                <td><span class="meta-value">{{ e($customer?->customer_name ?? '—') }}</span></td>
                <td><span class="meta-label">মোবাইল নং:</span></td>
                <td><span class="meta-value">{{ e($customer?->mobile ?? '—') }}</span></td>
            </tr>
            <tr>
                <td><span class="meta-label">ঠিকানা:</span></td>
                <td><span class="meta-value">{{ e($customer?->address ?? '—') }}</span></td>
                <td colspan="2">
                    <table style="width:100%; border-collapse:collapse;">
                        <tr>
                            <td style="border:none; border-right:1px solid #000; width:50%; padding:0 4px;">
                                <span class="meta-label" style="font-size:12px;">উপজেলা:</span>
                                <span class="meta-value" style="font-size:12px;">{{ e($customer?->upazila ?? $customer?->area ?? '—') }}</span>
                            </td>
                            <td style="border:none; padding:0 4px;">
                                <span class="meta-label" style="font-size:12px;">জেলা:</span>
                                <span class="meta-value" style="font-size:12px;">{{ e($customer?->district ?? '—') }}</span>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- ===== PRODUCT TABLE ===== --}}
        <table class="product-table">
            <thead>
                <tr>
                    <th style="width:6%;">ক্র নং</th>
                    <th style="width:38%;" class="col-product">পদার্থের নাম</th>
                    <th style="width:14%;">পরিমাণ</th>
                    <th style="width:8%;">একক</th>
                    <th style="width:14%;">দর</th>
                    <th style="width:20%;">টাকা</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pageItems as $item)
                    <tr>
                        <td class="col-sl">{{ $bengaliNum(++$globalSl) }}</td>
                        <td>{{ e($item->product?->product_name ?? 'Product #' . $item->product_id) }}</td>
                        <td class="col-qty">{{ number_format((float) $item->qty, 0) }}</td>
                        <td class="col-unit">Pcs</td>
                        <td class="col-rate">{{ number_format((float) $item->rate, 2) }}</td>
                        <td class="col-amount">{{ number_format((float) $item->qty * (float) $item->rate, 2) }}</td>
                    </tr>
                @endforeach

                {{-- Fill empty rows to maintain 17-row height on non-last pages --}}
                @for ($i = $pageItems->count(); $i < $itemsPerPage && !$isLastPage; $i++)
                    <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>
                @endfor

                {{-- ===== TOTALS (last page only, inside the table grid) ===== --}}
                @if ($isLastPage)
                    @php
                        $totalQty = $allItems->sum('qty');
                        $discount = (float) ($invoice->discount_amount ?? 0);
                        $vat = 0.00; // No VAT column in model
                        $deliveryCharge = (float) ($invoice->transport_cost ?? 0);
                        $subtotal = (float) $invoice->sub_total;
                        $grandTotal = (float) $invoice->total_amount;
                        $paid = (float) ($invoice->paid_amount ?? 0);
                        $previousDue = 0.00; // No previous_due column; due_amount covers it
                        $totalDue = (float) ($invoice->due_amount ?? 0);
                    @endphp
                    {{-- Subtotal --}}
                    <tr class="total-row">
                        <td colspan="2" class="total-label">মোট</td>
                        <td class="total-value">{{ number_format($totalQty, 0) }}</td>
                        <td style="text-align:center;">Pcs</td>
                        <td></td>
                        <td class="total-value">{{ number_format($subtotal, 2) }}</td>
                    </tr>
                    {{-- Discount --}}
                    <tr class="total-row">
                        <td colspan="5" class="total-label">ছাড়</td>
                        <td class="total-value">{{ number_format($discount, 2) }}</td>
                    </tr>
                    {{-- VAT --}}
                    <tr class="total-row">
                        <td colspan="5" class="total-label">ভ্যাটকরণ</td>
                        <td class="total-value">{{ number_format($vat, 2) }}</td>
                    </tr>
                    {{-- Delivery Charge --}}
                    <tr class="total-row">
                        <td colspan="5" class="total-label">ডেলিভারি খরচ</td>
                        <td class="total-value">{{ number_format($deliveryCharge, 2) }}</td>
                    </tr>
                    {{-- Grand Total (সর্বমোট) --}}
                    <tr class="total-row-grand">
                        <td colspan="5" class="total-label">সর্বমোট</td>
                        <td class="total-value">{{ number_format($grandTotal, 2) }}</td>
                    </tr>
                    {{-- Bengali words for the amount --}}
                    <tr class="total-row">
                        <td colspan="6" style="font-size:11px; font-style:italic; text-align:left;">
                            {{ $bengaliWords($grandTotal) }}
                        </td>
                    </tr>
                    {{-- Paid --}}
                    <tr class="total-row">
                        <td colspan="5" class="total-label">জমা</td>
                        <td class="total-value">{{ number_format($paid, 2) }}</td>
                    </tr>
                    {{-- Previous Due --}}
                    <tr class="total-row">
                        <td colspan="5" class="total-label">পূর্বের বকেয়া</td>
                        <td class="total-value">{{ number_format($previousDue, 2) }}</td>
                    </tr>
                    {{-- Total Due --}}
                    <tr class="total-row">
                        <td colspan="5" class="total-label">মোট বকেয়া</td>
                        <td class="total-value">{{ number_format($totalDue, 2) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

        {{-- ===== TERMS & CONDITIONS (last page only) ===== --}}
        @if ($isLastPage && $termsText)
            <div class="terms-section">
                <div class="terms-title">পূর্বে অবগতি:</div>
                <div>{!! nl2br(e($termsText)) !!}</div>
            </div>
        @endif

        {{-- ===== SIGNATURES (last page only) ===== --}}
        @if ($isLastPage)
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">গ্রাহকের স্বাক্ষর</div>
                </div>
                <div class="signature-box" style="text-align:right;">
                    @if ($signatoryName)
                        <div class="signature-name">{{ e($signatoryName) }}</div>
                        @if ($signatoryTitle)
                            <div style="font-size:11px; text-align:center;">{{ e($signatoryTitle) }}</div>
                        @endif
                    @endif
                    <div class="signature-line"></div>
                    <div class="signature-label">বিক্রেতার স্বাক্ষর</div>
                </div>
            </div>
        @endif

        {{-- ===== FOOTER (repeats on every page) ===== --}}
        <div class="invoice-footer" style="margin-top:12px;">
            @if ($hasFooterImage)
                <img src="{{ $footerImage }}" alt="Invoice Footer" style="width:100%; max-height:120px; object-fit:contain;">
            @endif
            @if ($footerText)
                <div style="font-size:10px; text-align:center; margin-top:4px;">{!! $footerText !!}</div>
            @endif
            <div class="footer-row">
                <div style="font-size:10px; color:#dc2626; font-weight:bold;">&#9733; STAR</div>
                <div class="footer-text">
                    নিশ্চিত নাম্যতা যুক্ত, আধুনিক নাগাদ পণ্য উৎপাদন কেন্দ্র
                </div>
                <div class="footer-page">Page {{ $page }} of {{ $totalPages }}</div>
            </div>
        </div>

        {{-- Page break (except on last page) --}}
        @if (!$isLastPage)
            <div class="page-break"></div>
        @endif
    @endfor
</body>
</html>
