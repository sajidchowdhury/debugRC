{{--
  layouts/print.blade.php — print layout (Phase 10 rebuild).

  Used by: print_blank_godown, print_godown, print_challan.
  Loads Bootstrap + rc-erp.css (Tailwind utilities + .write-in/.watermark
  custom classes from Phase 0). Body has .rc-erp-print-page class so the
  @media print block in rc-erp.css applies (A4 margins, 11px font, etc.).

  Each print view sets $branchCode (via @php before @extends) so the
  company header + toolbar use the branch's configured color. Falls back
  to amber (the brand color) if not set.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Print' }}</title>
    {{-- Cache-busted CSS links — filemtime() query param forces browser re-fetch
         after any CSS file change. Without this, nginx's "expires 1y; immutable"
         cache header can serve a stale (or previously-404) CSS response forever. --}}
    <link href="/assets/css/bootstrap.min.css?v={{ filemtime(public_path('assets/css/bootstrap.min.css')) }}" rel="stylesheet">
    <link href="/assets/css/all.min.css?v={{ filemtime(public_path('assets/css/all.min.css')) }}" rel="stylesheet">
    <link href="/assets/css/rc-erp.css?v={{ filemtime(public_path('assets/css/rc-erp.css')) }}" rel="stylesheet">
    @php
        $branchCode = $branchCode ?? null;
        $branchColorHex = \App\Support\BranchColor::hex($branchCode);
        $branchColorName = \App\Support\BranchColor::get($branchCode)['color_name'] ?? 'Amber';
    @endphp
    <style>
        body { background: #f8f9fa; font-family: 'Inter', 'Noto Sans Bengali', system-ui, sans-serif; }
        .print-container { max-width: 800px; margin: 0 auto; padding: 20px; }

        {{-- Toolbar (no-print) — branch-colored --}}
        .print-toolbar {
            position: sticky; top: 0; z-index: 1000;
            background: {{ $branchColorHex }}; padding: 10px 20px;
            display: flex; gap: 10px; align-items: center;
            margin-bottom: 20px; border-radius: 8px;
        }
        .print-toolbar button { color: white; }

        {{-- Print page --}}
        .print-page {
            background: white; padding: 30px; margin-bottom: 20px;
            border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            position: relative;
        }

        {{-- Company header — branch-colored border --}}
        .company-header { border-bottom: 3px solid {{ $branchColorHex }}; padding-bottom: 15px; margin-bottom: 20px; }
        .company-name { font-size: 1.5rem; font-weight: 700; color: {{ $branchColorHex }}; }
        .doc-title { font-size: 1.25rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: {{ $branchColorHex }}; }

        {{-- Meta grid --}}
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 20px; margin-bottom: 20px; }
        .meta-label { font-size: 0.75rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; }
        .meta-value { font-size: 0.95rem; font-weight: 600; }

        {{-- Items table — branch-tinted header --}}
        .items-table th { background: {{ $branchColorHex }}22; font-size: 0.8rem; text-transform: uppercase; }
        .items-table td { font-size: 0.9rem; }

        {{-- Totals + signatures --}}
        .totals-section { margin-top: 15px; margin-left: auto; width: 300px; }
        .totals-section dt { text-align: left; font-weight: 400; color: #6b7280; }
        .totals-section dd { text-align: right; font-weight: 600; }
        .signature-section { margin-top: 40px; display: flex; justify-content: space-between; }
        .signature-box { text-align: center; border-top: 1px solid #d1d5db; padding-top: 5px; width: 200px; }

        {{-- Watermark — uses .watermark class from rc-erp.css (absolute, rotated) --}}
        .watermark { color: rgba(200, 200, 200, 0.15); }

        {{-- Print-specific --}}
        @media print {
            .no-print { display: none !important; }
            .print-page { page-break-after: always; }
            .print-page:last-child { page-break-after: auto; }
            body { margin: 0; padding: 0; background: white !important; }
            .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
        }
    </style>
</head>
<body class="rc-erp-print-page">
    <div class="no-print print-toolbar">
        <button type="button" class="btn btn-light btn-sm" onclick="window.print()">
            <i class="fas fa-print me-1"></i> Print / প্রিন্ট
        </button>
        <button type="button" class="btn btn-outline-light btn-sm" onclick="window.close()">
            <i class="fas fa-times me-1"></i> Close / বন্ধ
        </button>
        <span class="text-white ms-auto small">{{ $title ?? '' }}</span>
    </div>

    <div class="print-container">
        @yield('print_content')
    </div>

    <script>
        // Auto-trigger print dialog on load (optional — user can cancel)
        window.addEventListener('load', function() {
            setTimeout(function() { window.print(); }, 300);
        });
    </script>
</body>
</html>
