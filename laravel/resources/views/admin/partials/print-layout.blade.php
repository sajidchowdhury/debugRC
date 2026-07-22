{{--
    Phase 19 (Task 19-RATELIMIT-PRINT) — Shared print-friendly layout for
    all admin master-data directory print views.

    Minimal HTML (no sidebar, no navbar, no JS frameworks). Bootstrap 5 +
    Font Awesome 6 are pulled from CDN so the view renders correctly even
    when opened directly from a print preview window without the main
    application shell.

    Sections:
      - print_title    (string)  — page H1
      - print_content  (html)    — the table body (rendered by the module's
                                   print.blade.php)

    The "Print" toolbar at the top uses `window.print()` and is hidden when
    actually printing via `@media print { .no-print { display: none } }`.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Print' }}</title>
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/all.min.css">
    <style>
        /* ===== Screen-only chrome ===== */
        body { background: #f3f4f6; margin: 0; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #111827; }
        .print-container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }

        .print-toolbar {
            display: flex; gap: 10px; align-items: center;
            background: #1f2937; padding: 10px 20px;
            border-radius: 8px; margin-bottom: 20px;
        }
        .print-toolbar .toolbar-title { color: #fff; font-weight: 600; margin-left: auto; font-size: 0.95rem; }

        /* ===== Company header ===== */
        .company-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1f2937; padding-bottom: 16px; margin-bottom: 24px; }
        .company-name { font-size: 1.6rem; font-weight: 800; color: #111827; line-height: 1.2; }
        .company-tagline { font-size: 0.85rem; color: #6b7280; margin-top: 4px; }
        .doc-title { font-size: 1.25rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: #1f2937; }
        .doc-meta { text-align: right; }

        /* ===== Filter summary ===== */
        .filter-summary { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; font-size: 0.85rem; color: #4b5563; }
        .filter-summary .filter-item { background: #f3f4f6; padding: 4px 10px; border-radius: 4px; }
        .filter-summary .filter-item strong { color: #111827; }

        /* ===== Table ===== */
        .print-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        .print-table thead th { background: #1f2937; color: #fff; text-align: left; padding: 10px 8px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; font-size: 0.75rem; border: 1px solid #1f2937; }
        .print-table tbody td { padding: 8px; border: 1px solid #d1d5db; vertical-align: top; }
        .print-table tbody tr:nth-child(even) td { background: #f9fafb; }
        .print-table tbody tr.empty td { text-align: center; padding: 40px 8px; color: #9ca3af; font-style: italic; }
        .badge-active   { background: #d1fae5; color: #065f46; padding: 2px 8px; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
        .badge-inactive { background: #f3f4f6; color: #6b7280; padding: 2px 8px; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }

        /* ===== Footer ===== */
        .print-footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #d1d5db; display: flex; justify-content: space-between; font-size: 0.78rem; color: #6b7280; }
        .print-footer strong { color: #374151; }

        /* ===== Print-only rules ===== */
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; margin: 0; padding: 0; }
            .print-container { max-width: 100%; box-shadow: none; padding: 0; border-radius: 0; }
            .print-table thead { display: table-header-group; }
            .print-table tbody tr { page-break-inside: avoid; }
            .print-table { font-size: 0.78rem; }
            @page { margin: 1.5cm; }
        }
    </style>
</head>
<body>
    {{-- Toolbar (hidden when printing) --}}
    <div class="no-print print-toolbar">
        <button type="button" class="btn btn-light btn-sm" onclick="window.print()" title="Print this page">
            <i class="fas fa-print me-1"></i> Print
        </button>
        <button type="button" class="btn btn-outline-light btn-sm" onclick="window.close()" title="Close this window">
            <i class="fas fa-times me-1"></i> Close
        </button>
        <span class="toolbar-title">{{ $title ?? '' }}</span>
    </div>

    <div class="print-container">
        {{-- Company header --}}
        <div class="company-header">
            <div>
                <div class="company-name">{{ config('app.name', 'Remote Center ERP') }}</div>
                <div class="company-tagline">Master Data Directory — Printed Report</div>
            </div>
            <div class="doc-meta">
                <div class="doc-title">{{ $label ?? 'Directory' }}</div>
                <div class="company-tagline">
                    Generated {{ \Illuminate\Support\Carbon::now()->format('d M Y, H:i') }}
                </div>
            </div>
        </div>

        {{-- Filter summary --}}
        @if (!empty($filters))
        <div class="filter-summary">
            <span class="filter-item"><strong>Filters:</strong></span>
            @foreach ($filters as $name => $value)
                <span class="filter-item"><strong>{{ $name }}:</strong> {{ $value }}</span>
            @endforeach
            <span class="filter-item"><strong>Rows:</strong> {{ $items->count() ?? 0 }}</span>
        </div>
        @else
        <div class="filter-summary">
            <span class="filter-item"><strong>Total rows:</strong> {{ $items->count() ?? 0 }}</span>
        </div>
        @endif

        {{-- Page-specific content --}}
        @yield('print_content')

        {{-- Footer --}}
        <div class="print-footer">
            <div>Generated by <strong>RC ERP</strong> &middot; {{ \Illuminate\Support\Carbon::now()->format('Y-m-d H:i:s') }}</div>
            <div>Page printed from {{ config('app.url', 'RC ERP') }}</div>
        </div>
    </div>

    <script>
        // Auto-trigger print dialog after the page loads (best-effort; user can cancel).
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 400);
        });
    </script>
</body>
</html>
