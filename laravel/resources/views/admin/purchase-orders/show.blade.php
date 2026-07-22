@extends('layouts.admin')

@push('css')
<link rel="stylesheet" href="/assets/css/purchase-index.css">
<link rel="stylesheet" href="/assets/css/purchase-order-details.css">
@endpush

@section('content')
@php
    // Laravel status → legacy purch-po-status-pill class (legacy uses
    // pending/partial/received, Laravel uses sent/partial/received).
    $statusClass = [
        'draft'     => 'draft',
        'sent'      => 'pending',
        'partial'   => 'partial',
        'received'  => 'received',
        'cancelled' => 'cancelled',
    ][$po->status] ?? 'draft';
    $statusLabel = [
        'draft'     => 'Draft',
        'sent'      => 'Sent',
        'partial'   => 'Partial',
        'received'  => 'Received',
        'cancelled' => 'Cancelled',
    ][$po->status] ?? ucfirst($po->status);

    $totalOrdered  = 0.0;
    $totalReceived = 0.0;
    foreach ($po->items as $item) {
        $totalOrdered  += (float) $item->qty;
        $totalReceived += (float) $item->received_qty;
    }
    $receivePct = $totalOrdered > 0 ? min(100, round(($totalReceived / $totalOrdered) * 100)) : 0;

    $formatMoney = fn ($n) => 'Tk ' . number_format((float) $n, 2);
    $formatQty   = function ($n) {
        $v = (float) $n;
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0';
    };

    $canEdit    = $po->isDraft();
    $canReceive = $po->canReceive();   // sent OR partial
    $canCancel  = $po->canCancel();    // draft OR sent
    $canMarkSent = $po->isDraft();

    $branchName = $po->branch?->branch_name ?? (auth()->user()?->branch?->branch_name ?? 'Branch');
@endphp

<div class="purch-index-app purch-po-detail container-fluid py-2">
    {{-- ─── Hero ──────────────────────────────────────────────────── --}}
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-file-invoice me-2"></i>{{ e($po->po_code) }}</h1>
            <p>Purchase order details — receipt progress and line items</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i>{{ e($branchName) }}</span>
            <span class="purch-po-status-pill {{ e($statusClass) }}">{{ e($statusLabel) }}</span>
        </div>
        <div class="purch-index-hero-actions d-flex gap-2 flex-wrap">
            @if ($canReceive)
                <a href="{{ route('admin.purchase-receives.create', ['po_id' => $po->id]) }}" class="btn btn-light btn-sm">
                    <i class="fas fa-dolly me-1"></i> Receive goods
                </a>
            @endif
            @if ($canEdit)
                <a href="{{ route('admin.purchase-orders.edit', $po) }}" class="btn btn-warning btn-sm">
                    <i class="fas fa-edit me-1"></i> Edit
                </a>
            @endif
            @if ($canMarkSent)
                <form method="POST" action="{{ route('admin.purchase-orders.markSent', $po) }}" id="markSentForm" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-info btn-sm" id="markSentBtn">
                        <i class="fas fa-paper-plane me-1"></i> Mark as Sent
                    </button>
                </form>
            @endif
            @if ($canCancel)
                <form method="POST" action="{{ route('admin.purchase-orders.cancel', $po) }}" id="cancelForm" class="m-0">
                    @csrf
                    <input type="hidden" name="cancel_reason" id="cancelReasonInput" value="">
                    <button type="button" class="btn btn-outline-danger btn-sm" id="cancelBtn">
                        <i class="fas fa-ban me-1"></i> Cancel PO
                    </button>
                </form>
            @endif
            <a href="{{ route('admin.purchase-orders.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> List
            </a>
        </div>
    </header>

    @if ($po->isCancelled())
        <div class="alert alert-secondary d-flex align-items-start mb-3" role="alert">
            <i class="fas fa-ban me-2 fa-lg text-secondary"></i>
            <div>
                <strong>This purchase order has been cancelled.</strong>
                @if (!empty($po->notes))
                    <div class="mt-1"><span class="text-muted">Cancellation note:</span>
                        <em>{{ $po->notes }}</em>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ─── 4 stat cards ─────────────────────────────────────────── --}}
    <div class="purch-po-detail-stats">
        <div class="purch-po-stat">
            <span class="label">Order total</span>
            <span class="value">{{ $formatMoney($po->total_amount) }}</span>
            @if (abs((float) $po->total_amount - (float) $po->sub_total) > 0.02)
                <span class="sub text-warning">Lines sum {{ $formatMoney($po->sub_total) }}</span>
            @endif
        </div>
        <div class="purch-po-stat">
            <span class="label">Receipt progress</span>
            <span class="value">{{ (int) $receivePct }}%</span>
            <span class="sub">{{ $formatQty($totalReceived) }} / {{ $formatQty($totalOrdered) }} units</span>
        </div>
        <div class="purch-po-stat">
            <span class="label">Supplier</span>
            <span class="value">{{ e($po->supplier?->supplier_name ?? '—') }}</span>
            @if ($po->supplier?->supplier_code)
                <span class="sub">{{ e($po->supplier->supplier_code) }}</span>
            @endif
        </div>
        <div class="purch-po-stat">
            <span class="label">Created by</span>
            <span class="value">{{ $po->created_by ? ('User #' . $po->created_by) : '—' }}</span>
            <span class="sub">PO date {{ optional($po->po_date)->format('d M Y') ?? '—' }}</span>
        </div>
    </div>

    {{-- ─── Progress bar ─────────────────────────────────────────── --}}
    @if ($totalOrdered > 0)
        <div class="purch-po-progress-wrap">
            <div class="progress" style="height: 8px;">
                <div class="progress-bar bg-success" role="progressbar"
                     style="width: {{ (int) $receivePct }}%"
                     aria-valuenow="{{ (int) $receivePct }}" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
        </div>
    @endif

    {{-- ─── 2-col grid: Dates + Notes ────────────────────────────── --}}
    <div class="purch-po-detail-grid">
        <section class="purch-po-detail-card">
            <h2><i class="fas fa-calendar-alt me-1"></i> Dates</h2>
            <dl>
                <dt>PO date</dt>
                <dd>{{ optional($po->po_date)->format('d M Y') ?? '—' }}</dd>
                <dt>Expected</dt>
                <dd>{{ $po->expected_date ? $po->expected_date->format('d M Y') : '—' }}</dd>
                <dt>Branch</dt>
                <dd>{{ e($po->branch?->branch_name ?? '—') }}</dd>
                <dt>Warehouse</dt>
                <dd>{{ e($po->warehouse?->warehouse_name ?? '— not specified —') }}</dd>
            </dl>
        </section>
        <section class="purch-po-detail-card">
            <h2><i class="fas fa-info-circle me-1"></i> Notes</h2>
            @if (!empty($po->notes))
                <p class="purch-po-remarks mb-0">{!! nl2br(e($po->notes)) !!}</p>
            @else
                <p class="text-muted mb-0 small">No remarks on this order.</p>
            @endif
        </section>
    </div>

    {{-- ─── Line items ───────────────────────────────────────────── --}}
    <section class="purch-po-detail-items">
        <div class="purch-po-detail-items-head">
            <h2><i class="fas fa-boxes me-1"></i> Line items</h2>
            <span class="text-muted small">{{ $po->items->count() }} product(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="text-center">Ordered</th>
                        <th class="text-center">Received</th>
                        <th class="text-center">Pending</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($po->items as $item)
                        @php
                            $qty     = (float) $item->qty;
                            $recv    = (float) $item->received_qty;
                            $pending = max(0, $qty - $recv);
                            $amount  = (float) $item->amount;
                            $lineDone = $qty > 0 && $recv >= $qty - 0.0001;
                        @endphp
                        <tr class="{{ $lineDone ? 'table-success' : ($recv > 0 ? 'table-warning' : '') }}">
                            <td>
                                <strong>{{ e($item->product?->product_name ?? 'Product #' . $item->product_id) }}</strong>
                                <div class="small text-muted">
                                    {{ e($item->product?->product_code ?? '') }}
                                    @if ($item->product?->unit) · {{ e($item->product->unit) }}@endif
                                </div>
                            </td>
                            <td class="text-center">{{ $formatQty($qty) }}</td>
                            <td class="text-center">{{ $formatQty($recv) }}</td>
                            <td class="text-center">
                                @if ($pending > 0)
                                    <span class="text-danger fw-semibold">{{ $formatQty($pending) }}</span>
                                @else
                                    <span class="text-success fw-semibold">0</span>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                            <td class="text-end fw-semibold">{{ number_format($amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No items.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="5" class="text-end">Sub-total</th>
                        <th class="text-end">{{ number_format((float) $po->sub_total, 2) }}</th>
                    </tr>
                    @if ((float) $po->discount_amount > 0)
                        <tr class="text-danger">
                            <th colspan="5" class="text-end">− Discount</th>
                            <th class="text-end">{{ number_format((float) $po->discount_amount, 2) }}</th>
                        </tr>
                    @endif
                    @if ((float) $po->tax_amount > 0)
                        <tr>
                            <th colspan="5" class="text-end">+ Tax</th>
                            <th class="text-end">{{ number_format((float) $po->tax_amount, 2) }}</th>
                        </tr>
                    @endif
                    <tr class="table-primary">
                        <th colspan="5" class="text-end">Total</th>
                        <th class="text-end">{{ number_format((float) $po->total_amount, 2) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</div>

@push('scripts')
<script>
$(function () {
    // ── Mark as Sent (SweetAlert2 confirm) ──
    $('#markSentBtn').on('click', function (e) {
        e.preventDefault();
        var $form = $('#markSentForm');
        Swal.fire({
            icon: 'question',
            title: 'Mark PO as sent?',
            text: 'Once marked as sent, this PO cannot be edited. You can still cancel it.',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-paper-plane"></i> Yes, mark sent',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#0ea5e9',
            reverseButtons: true
        }).then(function (result) {
            if (result.isConfirmed) $form.submit();
        });
    });

    // ── Cancel (SweetAlert2 prompt for reason — required) ──
    $('#cancelBtn').on('click', function (e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Cancel this PO?',
            html: '<p class="text-muted small mb-2">This action cannot be undone. Please provide a reason:</p>' +
                  '<input type="text" id="swalCancelReason" class="form-control" placeholder="Reason for cancellation" maxlength="500">',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-ban"></i> Cancel PO',
            cancelButtonText: 'Keep PO',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
            focusConfirm: false,
            preConfirm: function () {
                var reason = $('#swalCancelReason').val();
                if (!reason || !reason.trim()) {
                    Swal.showValidationMessage('A cancellation reason is required.');
                    return false;
                }
                return reason;
            }
        }).then(function (result) {
            if (result.isConfirmed && result.value) {
                $('#cancelReasonInput').val(result.value);
                $('#cancelForm').submit();
            }
        });
    });
});
</script>
@endpush
@endsection
