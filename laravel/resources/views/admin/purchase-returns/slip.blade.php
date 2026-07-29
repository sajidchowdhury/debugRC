@extends('layouts.admin')

@section('content')
@php
    $r = $return;
    $supplierName = $r->supplier?->supplier_name ?? '—';
    $supplierMobile = $r->supplier?->mobile ?? $r->supplier?->phone ?? 'N/A';
    $grnCode = $r->purchaseReceive?->receive_code ?? '—';
    $branchName = $r->branch?->branch_name ?? '—';
    $returnDate = \Carbon\Carbon::parse($r->return_date)->format('d-m-Y');
    $creatorName = $r->creator?->username ?? 'System';
    if ($r->creator && $r->creator->employee?->name) {
        $creatorName = $r->creator->employee->name . ' (' . $r->creator->username . ')';
    }
    $total = 0.0;
@endphp

<div class="container-fluid py-3 purch-return-slip-app">
    <div class="card shadow purch-return-slip-card" id="printableArea">

        {{-- Header --}}
        <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center purch-slip-header">
            <h4 class="mb-0">
                <i class="fas fa-undo"></i> Purchase Return Slip
            </h4>
            <div class="d-flex gap-2 no-print">
                <button onclick="window.print()" class="btn btn-light btn-sm">
                    <i class="fas fa-print"></i> Print Slip
                </button>
                <a href="{{ route('admin.purchase-returns.show', $r) }}" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        {{-- Slip Content --}}
        <div class="card-body purch-slip-body">

            <div class="text-center mb-4 purch-slip-title">
                <h3 class="mb-1">REMOTE CENTER</h3>
                <h5 class="text-danger">PURCHASE RETURN SLIP</h5>
                <strong>Return Code: {{ $r->return_code }}</strong>
            </div>

            <div class="row mb-4 purch-slip-meta">
                <div class="col-md-6">
                    <strong>Supplier:</strong> {{ $supplierName }}<br>
                    <strong>Mobile:</strong> {{ $supplierMobile }}<br>
                    <strong>GRN Reference:</strong> {{ $grnCode }}
                </div>
                <div class="col-md-6 text-md-end">
                    <strong>Branch:</strong> {{ $branchName }}<br>
                    <strong>Date:</strong> {{ $returnDate }}<br>
                    <strong>Created By:</strong> {{ $creatorName }}
                </div>
            </div>

            {{-- Items Table --}}
            <div class="table-responsive purch-slip-table">
                <table class="table table-bordered table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 40px">#</th>
                            <th>Product</th>
                            <th>Warehouse</th>
                            <th class="text-center">Return Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Amount</th>
                            <th>Condition</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($r->items as $i => $item)
                            @php
                                $amount = (float) $item->qty * (float) $item->rate;
                                $total += $amount;
                            @endphp
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>
                                    <strong>{{ $item->product?->product_name ?? '—' }}</strong><br>
                                    <small>{{ $item->product?->product_code ?? '' }}</small>
                                </td>
                                <td>{{ $item->warehouse?->warehouse_name ?? '—' }}</td>
                                <td class="text-center">{{ number_format((float) $item->qty, 2) }}</td>
                                <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                                <td class="text-end">{{ number_format($amount, 2) }}</td>
                                <td>
                                    @if ($item->isDamage())
                                        <span class="badge bg-warning text-dark">Damage</span>
                                    @else
                                        <span class="badge bg-success">Good</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="5" class="text-end"><strong>Total Amount</strong></th>
                            <th class="text-end"><strong>{{ number_format($total, 2) }}</strong></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if (!empty($r->reason))
                <div class="mt-4 p-3 bg-light border rounded purch-slip-reason">
                    <strong>Reason for Return:</strong><br>
                    {{ $r->reason }}
                </div>
            @endif

            <div class="row mt-5 purch-slip-signatures">
                <div class="col-6 text-center">
                    <p>___________________________</p>
                    <strong>Received By (Supplier)</strong>
                </div>
                <div class="col-6 text-center">
                    <p>___________________________</p>
                    <strong>Authorized By</strong>
                </div>
            </div>

        </div>

        <div class="card-footer text-center purch-slip-footer">
            <small class="text-muted">Thank you for your business | Remote Center ERP</small>
        </div>
    </div>
</div>
@endsection

@push('css')
<style>
    .purch-return-slip-card { max-width: 900px; margin: 0 auto; }
    .purch-slip-title h3 { letter-spacing: 0.08em; font-weight: 700; }
    .purch-slip-title h5 { letter-spacing: 0.06em; font-weight: 600; }
    .purch-slip-table table { font-size: 0.9rem; }
    .purch-slip-signatures p { margin-bottom: 0.25rem; letter-spacing: 2px; }

    @media print {
        .sidebar, .navbar, .main-content > .topbar,
        .purch-slip-header .btn, .purch-slip-footer,
        .no-print, .btn, nav, header, footer {
            display: none !important;
        }
        #printableArea {
            margin: 0;
            padding: 20px;
            max-width: 100%;
        }
        body { background: white !important; }
        .purch-return-slip-card {
            border: none !important;
            box-shadow: none !important;
        }
        .purch-slip-header { background: #dc2626 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .table-dark { background: #212529 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table { border-collapse: collapse !important; }
        th, td { border: 1px solid #000 !important; }
        .badge { border: 1px solid #000; padding: 2px 6px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
@endpush
