@extends('layouts.admin')

@section('content')
@php
    // Pre-compute per-product availability helpers
    $availability = $availability ?? [];

    $availForProduct = function (int $productId) use ($availability) {
        return $availability[$productId] ?? collect();
    };

    $totalAvailForProduct = function (int $productId) use ($availability) {
        $rows = $availability[$productId] ?? collect();
        return (float) $rows->sum->qty;
    };

    $invoiceTotal = (float) ($invoice->total_amount ?? 0);
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-warehouse me-2"></i>{{ $title }}
            </h1>
            <p class="mb-0 small opacity-75">
                Step 1 of 2 — Assign a source warehouse to each invoice line before issuing the delivery challan.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to invoice
            </a>
        </div>
    </header>

    {{-- Invoice summary card --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-muted small">Invoice code</div>
                    <div class="fw-semibold">
                        <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="text-decoration-none">
                            {{ $invoice->invoice_code }}
                        </a>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Invoice date</div>
                    <div class="fw-semibold">
                        {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') }}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Customer</div>
                    <div class="fw-semibold">
                        @if ($invoice->customer)
                            {{ $invoice->customer->customer_name }}
                            <div class="small text-muted">{{ $invoice->customer->customer_code }}</div>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Branch</div>
                    <div class="fw-semibold">
                        @if ($invoice->branch)
                            {{ $invoice->branch->branch_name }}
                            <span class="small text-muted">({{ $invoice->branch->branch_code }})</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Total amount</div>
                    <div class="fw-semibold" style="color:#7c3aed;">Tk {{ number_format($invoiceTotal, 2) }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Line items</div>
                    <div class="fw-semibold">{{ number_format($invoice->items->count()) }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Info banner --}}
    <div class="alert alert-info d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-circle-info me-2 mt-1"></i>
        <div>
            Assign a warehouse for each product. Stock availability shown per warehouse for this branch.
            @if ($warehouses->isEmpty())
                <strong class="text-danger">No active warehouses configured for this branch.</strong>
            @endif
        </div>
    </div>

    {{-- Godown assignment form --}}
    <form method="POST" action="{{ route('admin.sales-challans.storeGodown', $invoice) }}">
        @csrf
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex align-items-center justify-content-between">
                <h2 class="h6 mb-0">
                    <i class="fas fa-boxes-stacked me-1" style="color:#7c3aed;"></i> Godown assignment
                </h2>
                <span class="badge bg-primary-subtle text-primary">
                    {{ $invoice->items->count() }} line(s)
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:240px;">Product</th>
                                <th class="text-end">Qty needed</th>
                                <th style="min-width:260px;">Warehouse</th>
                                <th style="min-width:280px;">Available qty (per warehouse)</th>
                                <th class="text-end">Avg cost (Tk)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice->items as $item)
                                @php
                                    $rows = $availForProduct($item->product_id);
                                    $totalAvail = $totalAvailForProduct($item->product_id);
                                    $short = $totalAvail < (float) $item->qty;
                                @endphp
                                <tr class="{{ $short ? 'table-warning' : '' }}">
                                    <td>
                                        @if ($item->product)
                                            <div class="fw-semibold">{{ $item->product->product_name }}</div>
                                            <div class="small text-muted">{{ $item->product->product_code }}</div>
                                        @else
                                            <span class="text-muted">Product #{{ $item->product_id }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end fw-semibold">{{ number_format((float) $item->qty, 2) }}</td>
                                    <td>
                                        @if ($warehouses->isEmpty())
                                            <span class="badge bg-danger-subtle text-danger">
                                                <i class="fas fa-ban me-1"></i>No warehouses
                                            </span>
                                        @else
                                            <select name="warehouse_assignments[{{ $item->id }}]"
                                                    class="form-select form-select-sm warehouse-select"
                                                    data-item-id="{{ $item->id }}"
                                                    data-product-id="{{ $item->product_id }}"
                                                    data-qty="{{ $item->qty }}"
                                                    required>
                                                <option value="">— select warehouse —</option>
                                                @foreach ($warehouses as $w)
                                                    @php
                                                        $row = $rows->firstWhere('warehouse_id', $w->id);
                                                        $wQty = $row ? (float) $row->qty : 0.0;
                                                        $wCost = $row ? (float) $row->avg_cost : 0.0;
                                                    @endphp
                                                    <option value="{{ $w->id }}"
                                                            data-qty="{{ $wQty }}"
                                                            data-avg-cost="{{ $wCost }}"
                                                            @if ($wQty < (float) $item->qty) disabled @endif>
                                                        {{ $w->warehouse_name }}
                                                        · {{ number_format($wQty, 2) }} on hand
                                                        @if ($wQty < (float) $item->qty) · insufficient @endif
                                                    </option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($rows->isEmpty())
                                            <span class="badge bg-danger-subtle text-danger">
                                                <i class="fas fa-triangle-exclamation me-1"></i>No stock in any warehouse
                                            </span>
                                        @else
                                            <ul class="list-unstyled mb-0 small">
                                                @foreach ($rows as $row)
                                                    <li>
                                                        <span class="text-muted">{{ $row->warehouse_name }}:</span>
                                                        <span class="fw-semibold
                                                              {{ (float) $row->qty >= (float) $item->qty ? 'text-success' : 'text-danger' }}">
                                                            {{ number_format((float) $row->qty, 2) }}
                                                        </span>
                                                        <span class="text-muted">@ {{ number_format((float) $row->avg_cost, 2) }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($rows->isNotEmpty())
                                            <span class="avg-cost-display text-muted" id="avg-cost-{{ $item->id }}">—</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-primary btn-sm"
                        @if ($warehouses->isEmpty()) disabled @endif>
                    <i class="fas fa-check me-1"></i> Confirm Godown Assignment
                </button>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
$(function () {
    $('.warehouse-select').select2({ theme: 'bootstrap-5', width: '100%' });

    // On warehouse change, show that warehouse's avg_cost next to the row.
    $('.warehouse-select').on('select2:select', function (e) {
        var $sel = $(this);
        var itemId = $sel.data('item-id');
        var opt = $sel.find('option:selected');
        var avgCost = opt.data('avg-cost') || 0;
        var disp = $('#avg-cost-' + itemId);
        if (disp.length) {
            disp.removeClass('text-muted')
                .addClass('fw-semibold')
                .text(parseFloat(avgCost).toFixed(2));
        }
    });

    // Pre-fill displays if any option is already selected (e.g. on back-with-input).
    $('.warehouse-select').each(function () {
        var $sel = $(this);
        var itemId = $sel.data('item-id');
        var opt = $sel.find('option:selected');
        if (opt.val()) {
            var avgCost = opt.data('avg-cost') || 0;
            var disp = $('#avg-cost-' + itemId);
            if (disp.length) {
                disp.removeClass('text-muted')
                    .addClass('fw-semibold')
                    .text(parseFloat(avgCost).toFixed(2));
            }
        }
    });

    // Intercept submit if any row has insufficient stock selected (defensive).
    $('form').on('submit', function (e) {
        var ok = true;
        $('.warehouse-select').each(function () {
            if (!$(this).val()) ok = false;
        });
        if (!ok) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Incomplete assignment',
                text: 'Please assign a warehouse to every line item before confirming.',
                confirmButtonColor: '#7c3aed'
            });
        }
    });
});
</script>
@endpush
@endsection
