@extends('layouts.admin')

@section('content')
@php
    $statusBadge = function (bool $large = false) use ($po): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        return [
            'draft'     => '<span class="badge bg-warning-subtle text-warning' . $cls . '"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'sent'      => '<span class="badge bg-info-subtle text-info' . $cls . '"><i class="fas fa-paper-plane me-1"></i>Sent</span>',
            'partial'   => '<span class="badge bg-primary-subtle text-primary' . $cls . '"><i class="fas fa-circle-half-stroke me-1"></i>Partial</span>',
            'received'  => '<span class="badge bg-success-subtle text-success' . $cls . '"><i class="fas fa-circle-check me-1"></i>Received</span>',
            'cancelled' => '<span class="badge bg-secondary-subtle text-secondary' . $cls . '"><i class="fas fa-ban me-1"></i>Cancelled</span>',
        ][$po->status] ?? '<span class="badge bg-light text-dark' . $cls . '">' . e($po->status) . '</span>';
    };

    // Reception summary
    $totalOrdered  = 0;
    $totalReceived = 0;
    foreach ($po->items as $item) {
        $totalOrdered  += (float) $item->qty;
        $totalReceived += (float) $item->received_qty;
    }
    $receptionPct = $totalOrdered > 0 ? min(100, ($totalReceived / $totalOrdered) * 100) : 0;
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#2563eb,#1d4ed8);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-cart-shopping me-2"></i>PO {{ $po->po_code }}
                {!! $statusBadge() !!}
            </h1>
            <p class="mb-0 small opacity-75">
                @if ($po->supplier){{ $po->supplier->supplier_name }}@endif
                @if ($po->branch) · {{ $po->branch->branch_name }}@endif
                @if ($po->warehouse) · {{ $po->warehouse->warehouse_name }}@endif
            </p>
        </div>
        <div>
            <a href="{{ route('admin.purchase-orders.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Cancelled banner --}}
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

    <div class="row g-3">
        {{-- Left: main details --}}
        <div class="col-lg-8">
            {{-- PO details card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-primary"></i> PO details</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">PO code</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-secondary-subtle text-secondary">{{ $po->po_code }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">PO date</dt>
                        <dd class="col-sm-9">{{ \Carbon\Carbon::parse($po->po_date)->format('d M Y') }}</dd>

                        <dt class="col-sm-3 text-muted">Supplier</dt>
                        <dd class="col-sm-9">
                            @if ($po->supplier)
                                <strong>{{ $po->supplier->supplier_name }}</strong>
                                <span class="text-muted">({{ $po->supplier->supplier_code }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">
                            @if ($po->branch)
                                {{ $po->branch->branch_name }}
                                <span class="text-muted small">({{ $po->branch->branch_code }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Warehouse</dt>
                        <dd class="col-sm-9">
                            @if ($po->warehouse)
                                <strong>{{ $po->warehouse->warehouse_name }}</strong>
                                <span class="text-muted">({{ $po->warehouse->warehouse_code }})</span>
                            @else
                                <span class="text-muted">— not specified —</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Expected date</dt>
                        <dd class="col-sm-9">
                            @if ($po->expected_date)
                                {{ \Carbon\Carbon::parse($po->expected_date)->format('d M Y') }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Notes</dt>
                        <dd class="col-sm-9">{!! nl2br(e($po->notes ?: '—')) !!}</dd>

                        <dt class="col-sm-3 text-muted">Sub-total</dt>
                        <dd class="col-sm-9">Tk {{ number_format((float) $po->sub_total, 2) }}</dd>

                        <dt class="col-sm-3 text-muted">Discount</dt>
                        <dd class="col-sm-9 text-danger">− Tk {{ number_format((float) $po->discount_amount, 2) }}</dd>

                        <dt class="col-sm-3 text-muted">Tax</dt>
                        <dd class="col-sm-9">+ Tk {{ number_format((float) $po->tax_amount, 2) }}</dd>

                        <dt class="col-sm-3 text-muted">Total amount</dt>
                        <dd class="col-sm-9">
                            <strong class="text-primary fs-5">Tk {{ number_format((float) $po->total_amount, 2) }}</strong>
                        </dd>

                        <dt class="col-sm-3 text-muted">Created by</dt>
                        <dd class="col-sm-9 small text-muted">
                            @if ($po->created_by) User #{{ $po->created_by }} @else — @endif
                            @if ($po->created_at) · {{ $po->created_at->format('d M Y H:i') }} @endif
                        </dd>
                    </dl>
                </div>
            </div>

            {{-- Items table --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-table-list me-1 text-primary"></i> Items
                        <span class="badge bg-primary-subtle text-primary ms-1">{{ $po->items->count() }}</span>
                    </h2>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Qty Ordered</th>
                                    <th class="text-end">Qty Received</th>
                                    <th class="text-end">Remaining</th>
                                    <th class="text-end">Rate (Tk)</th>
                                    <th class="text-end">Amount (Tk)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($po->items as $item)
                                    @php
                                        $remaining = $item->remainingQty();
                                        $fully     = $item->isFullyReceived();
                                    @endphp
                                    <tr class="{{ $fully ? 'table-success' : '' }}">
                                        <td>
                                            @if ($item->product)
                                                <span class="fw-semibold">{{ $item->product->product_name }}</span>
                                                <div class="small text-muted">{{ $item->product->product_code }}</div>
                                            @else
                                                <span class="text-muted">Product #{{ $item->product_id }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ number_format((float) $item->qty, 4) }}</td>
                                        <td class="text-end">
                                            {{ number_format((float) $item->received_qty, 4) }}
                                            @if ($fully)
                                                <i class="fas fa-circle-check text-success ms-1"></i>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if ($remaining > 0)
                                                <span class="text-danger fw-semibold">
                                                    {{ number_format($remaining, 4) }}
                                                </span>
                                            @else
                                                <span class="text-success fw-semibold">0.0000</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $item->amount, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">No items.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td colspan="5" class="text-end">Sub-total</td>
                                    <td class="text-end">Tk {{ number_format((float) $po->sub_total, 2) }}</td>
                                </tr>
                                <tr class="table-light">
                                    <td colspan="5" class="text-end text-danger">− Discount</td>
                                    <td class="text-end text-danger">Tk {{ number_format((float) $po->discount_amount, 2) }}</td>
                                </tr>
                                <tr class="table-light">
                                    <td colspan="5" class="text-end">+ Tax</td>
                                    <td class="text-end">Tk {{ number_format((float) $po->tax_amount, 2) }}</td>
                                </tr>
                                <tr class="table-primary fw-bold">
                                    <td colspan="5" class="text-end">Total amount</td>
                                    <td class="text-end">Tk {{ number_format((float) $po->total_amount, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Reception Status card (only if any received) --}}
            @if ($po->isPartial() || $po->isReceived())
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-truck-ramp-box me-1 text-success"></i> Reception status
                        </h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 align-items-center">
                            <div class="col-md-4">
                                <div class="small text-muted">Total qty ordered</div>
                                <div class="h5 mb-0">{{ number_format($totalOrdered, 4) }}</div>
                            </div>
                            <div class="col-md-4">
                                <div class="small text-muted">Total qty received</div>
                                <div class="h5 mb-0 text-success">{{ number_format($totalReceived, 4) }}</div>
                            </div>
                            <div class="col-md-4">
                                <div class="small text-muted">Remaining</div>
                                <div class="h5 mb-0 {{ ($totalOrdered - $totalReceived) > 0 ? 'text-danger' : 'text-success' }}">
                                    {{ number_format(max(0, $totalOrdered - $totalReceived), 4) }}
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-success"
                                         role="progressbar"
                                         style="width: {{ round($receptionPct, 1) }}%;"
                                         aria-valuenow="{{ round($receptionPct, 1) }}"
                                         aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <div class="small text-muted mt-1">
                                    {{ round($receptionPct, 1) }}% received
                                    @if ($po->isReceived())
                                        · <span class="text-success fw-semibold">fully received</span>
                                    @elseif ($po->isPartial())
                                        · <span class="text-primary fw-semibold">partial reception</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Right: aside actions --}}
        <div class="col-lg-4">
            {{-- Status card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-flag me-1 text-primary"></i> Status</h2>
                </div>
                <div class="card-body text-center">
                    <div class="mb-2">{!! $statusBadge(true) !!}</div>
                    <div class="small text-muted">
                        @switch($po->status)
                            @case('draft')
                                PO created — not yet sent to supplier. Editable.
                                @break
                            @case('sent')
                                Sent to supplier. Awaiting delivery.
                                @break
                            @case('partial')
                                Some items received via GRN. Awaiting the rest.
                                @break
                            @case('received')
                                All items fully received. PO closed.
                                @break
                            @case('cancelled')
                                Cancelled. No further action possible.
                                @break
                        @endswitch
                    </div>
                </div>
            </div>

            {{-- Actions card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-bolt me-1 text-primary"></i> Actions</h2>
                </div>
                <div class="card-body d-grid gap-2">
                    {{-- Draft: edit + mark as sent --}}
                    @if ($po->isDraft())
                        <a href="{{ route('admin.purchase-orders.edit', $po) }}"
                           class="btn btn-outline-primary">
                            <i class="fas fa-pen-to-square me-1"></i> Edit PO
                        </a>
                        <form method="POST" action="{{ route('admin.purchase-orders.mark-sent', $po) }}" id="markSentForm">
                            @csrf
                            <button type="submit" class="btn btn-info w-100" id="markSentBtn">
                                <i class="fas fa-paper-plane me-1"></i> Mark as Sent
                            </button>
                        </form>
                    @endif

                    {{-- Cancel (draft or sent) --}}
                    @if ($po->canCancel())
                        <form method="POST" action="{{ route('admin.purchase-orders.cancel', $po) }}" id="cancelForm">
                            @csrf
                            <input type="hidden" name="cancel_reason" id="cancelReasonInput" value="">
                            <button type="button" class="btn btn-outline-danger w-100" id="cancelBtn">
                                <i class="fas fa-ban me-1"></i> Cancel PO
                            </button>
                        </form>
                    @endif

                    {{-- Receive goods (sent or partial) — Phase 0 BUG-8 fix:
                         Replaced stale "Phase 7.2 not implemented" alert with
                         a real "Receive against this PO" button that links to
                         the GRN create page with ?po_id= pre-fill. The GRN
                         module (Phase 7.2) has been implemented since the
                         initial Laravel scaffold. --}}
                    @if ($po->canReceive())
                        <a href="{{ route('admin.purchase-receives.create', ['po_id' => $po->id]) }}"
                           class="btn btn-success w-100">
                            <i class="fas fa-truck-ramp-box me-1"></i> Receive against this PO
                        </a>
                    @endif

                    @if ($po->isReceived())
                        <div class="alert alert-success small mb-0">
                            <i class="fas fa-circle-check me-1"></i>
                            PO fully received — no further action required.
                        </div>
                    @endif

                    @if ($po->isCancelled())
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-ban me-1"></i>
                            This PO is cancelled and cannot be modified.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    // ====== Mark as Sent (SweetAlert2 confirm) ======
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
            if (result.isConfirmed) {
                $form.submit();
            }
        });
    });

    // ====== Cancel (SweetAlert2 prompt for reason — required) ======
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
