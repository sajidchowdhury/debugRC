<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Print' }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* Print-specific styles */
        @media print {
            .no-print { display: none !important; }
            .print-page { page-break-after: always; }
            .print-page:last-child { page-break-after: auto; }
            body { margin: 0; padding: 0; }
            .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
        }
        body { background: #f8f9fa; }
        .print-container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .print-toolbar {
            position: sticky; top: 0; z-index: 1000;
            background: #4f46e5; padding: 10px 20px;
            display: flex; gap: 10px; align-items: center;
            margin-bottom: 20px; border-radius: 8px;
        }
        .print-toolbar button { color: white; }
        .print-page {
            background: white; padding: 30px; margin-bottom: 20px;
            border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .company-header { border-bottom: 2px solid #4f46e5; padding-bottom: 15px; margin-bottom: 20px; }
        .company-name { font-size: 1.5rem; font-weight: 700; color: #4f46e5; }
        .doc-title { font-size: 1.25rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 20px; margin-bottom: 20px; }
        .meta-label { font-size: 0.75rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; }
        .meta-value { font-size: 0.95rem; font-weight: 600; }
        .items-table th { background: #f3f4f6; font-size: 0.8rem; text-transform: uppercase; }
        .items-table td { font-size: 0.9rem; }
        .totals-section { margin-top: 15px; margin-left: auto; width: 300px; }
        .totals-section dt { text-align: left; font-weight: 400; color: #6b7280; }
        .totals-section dd { text-align: right; font-weight: 600; }
        .signature-section { margin-top: 40px; display: flex; justify-content: space-between; }
        .signature-box { text-align: center; border-top: 1px solid #d1d5db; padding-top: 5px; width: 200px; }
        .watermark {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 4rem; color: rgba(239, 68, 68, 0.15); font-weight: 700; pointer-events: none;
        }
    </style>
</head>
<body>
    <div class="no-print print-toolbar">
        <button type="button" class="btn btn-light btn-sm" onclick="window.print()">
            <i class="fas fa-print me-1"></i> Print
        </button>
        <button type="button" class="btn btn-outline-light btn-sm" onclick="window.close()">
            <i class="fas fa-times me-1"></i> Close
        </button>
        <span class="text-white ms-auto small">{{ $title ?? '' }}</span>
    </div>

    <div class="print-container">
        @yield('print_content')
    </div>

    <script>
        // Auto-trigger print dialog on load (optional — user can cancel)
        window.addEventListener('load', function() {
            // Small delay to ensure rendering completes
            setTimeout(function() { window.print(); }, 300);
        });
    </script>
</body>
</html>
