<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer {{ $transfer->transfer_code }}</title>
    <style>
        @page { margin: 15mm; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; color: #1a1a1a; margin: 0; padding: 20px; }
        .header { text-align: center; border-bottom: 2px solid #ea580c; padding-bottom: 10px; margin-bottom: 15px; }
        .header h1 { font-size: 18px; margin: 0 0 4px; color: #ea580c; }
        .header .subtitle { font-size: 11px; color: #666; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 15px; }
        .info-grid .label { font-weight: 600; color: #555; font-size: 11px; }
        .info-grid .value { font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; font-size: 11px; }
        th { background: #f8f9fa; font-weight: 600; }
        .text-end { text-align: right; }
        .fw-bold { font-weight: 700; }
        .total-row td { background: #f8f9fa; font-weight: 700; }
        .status-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .status-draft { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #d1e7dd; color: #0f5132; }
        .status-cancelled { background: #e2e3e5; color: #6c757d; }
        .footer { margin-top: 20px; border-top: 1px solid #ddd; padding-top: 10px; font-size: 10px; color: #999; display: flex; justify-content: space-between; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 40px; margin-top: 40px; }
        .signatures .sig-box { text-align: center; }
        .signatures .sig-line { border-top: 1px solid #333; margin-top: 50px; padding-top: 4px; font-size: 10px; color: #666; }
        @media print { body { padding: 0; } }
    </style>
</head>
<body>
    @php
        $t = $transfer;
        $statusClass = ['draft' => 'status-draft', 'confirmed' => 'status-confirmed', 'cancelled' => 'status-cancelled'];
    @endphp

    <div class="header">
        <h1>Warehouse Transfer</h1>
        <div class="subtitle">{{ $t->transfer_code }} · {{ \Carbon\Carbon::parse($t->transfer_date)->format('d M Y') }}</div>
    </div>

    <div class="info-grid">
        <div>
            <div class="label">From Warehouse</div>
            <div class="value">{{ $t->fromWarehouse?->warehouse_name ?? '—' }}</div>
        </div>
        <div>
            <div class="label">To Warehouse</div>
            <div class="value">{{ $t->toWarehouse?->warehouse_name ?? '—' }}</div>
        </div>
        <div>
            <div class="label">Branch</div>
            <div class="value">{{ $t->fromBranch?->branch_name ?? '—' }}</div>
        </div>
        <div>
            <div class="label">Status</div>
            <div class="value"><span class="status-badge {{ $statusClass[$t->status] ?? '' }}">{{ ucfirst($t->status) }}</span></div>
        </div>
        <div>
            <div class="label">Created</div>
            <div class="value">{{ optional($t->created_at)->format('d M Y H:i') }} @if ($t->created_by) · by user #{{ $t->created_by }} @endif</div>
        </div>
        <div>
            <div class="label">Notes</div>
            <div class="value">{{ $t->notes ?: '—' }}</div>
        </div>
    </div>

    @if ($t->is_reversed)
        <div style="background:#fff3cd;border:1px solid #ffc107;padding:6px 10px;border-radius:4px;margin-bottom:10px;font-size:11px;">
            <strong>Reversed</strong>
            @if ($t->reversed_at) on {{ \Carbon\Carbon::parse($t->reversed_at)->format('d M Y H:i') }} @endif
            @if ($t->reversed_by) by user #{{ $t->reversed_by }} @endif
            @if ($t->reverse_reason) — Reason: {{ $t->reverse_reason }} @endif
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th style="width:5%;">#</th>
                <th>Product</th>
                <th class="text-end" style="width:12%;">Qty</th>
                <th class="text-end" style="width:15%;">Rate (Tk)</th>
                <th class="text-end" style="width:18%;">Amount (Tk)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($t->items as $idx => $item)
                <tr>
                    <td>{{ $idx + 1 }}</td>
                    <td>
                        @if ($item->product)
                            {{ $item->product->product_name }}
                            <span style="color:#999;">({{ $item->product->product_code }})</span>
                        @else
                            Product #{{ $item->product_id }}
                        @endif
                    </td>
                    <td class="text-end">{{ number_format((float) $item->qty, 4) }}</td>
                    <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                    <td class="text-end">{{ number_format($item->amount(), 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="4" class="text-end">Total</td>
                <td class="text-end">Tk {{ number_format((float) $t->total_amount, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="signatures">
        <div class="sig-box">
            <div class="sig-line">Prepared by</div>
        </div>
        <div class="sig-box">
            <div class="sig-line">Approved by</div>
        </div>
        <div class="sig-box">
            <div class="sig-line">Received by</div>
        </div>
    </div>

    <div class="footer">
        <div>Warehouse Transfer — {{ $t->transfer_code }}</div>
        <div>Printed on {{ now()->format('d M Y H:i') }}</div>
    </div>

    <script>window.onload = function() { window.print(); }</script>
</body>
</html>
