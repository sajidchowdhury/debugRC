@extends('layouts.admin')

@section('content')
@php
    $totalCogs = (float) ($totalCogs ?? 0);
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-truck-fast me-2"></i>{{ $title }}
            </h1>
            <p class="mb-0 small opacity-75">
                Step 2 of 2 — Issue the delivery challan. Stock moves OUT and GL posts Dr COGS / Cr Inventory.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to invoice
            </a>
        </div>
    </header>

    {{-- Info banner --}}
    <div class="alert alert-warning d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-triangle-exclamation me-2 mt-1"></i>
        <div>
            <strong>Issuing the challan will move stock OUT</strong> at the current avg_cost for each line item's
            assigned warehouse and post a GL entry (Dr COGS / Cr Inventory). This action cannot be undone from this
            screen — to reverse, use the <em>Cancel Challan</em> action on the challan detail page (which reverses
            stock + GL).
        </div>
    </div>

    {{-- Invoice summary mini-card --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-md-3">
                    <span class="text-muted small">Invoice:</span>
                    <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="fw-semibold text-decoration-none">
                        {{ $invoice->invoice_code }}
                    </a>
                </div>
                <div class="col-md-3">
                    <span class="text-muted small">Date:</span>
                    <span class="fw-semibold">
                        {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') }}
                    </span>
                </div>
                <div class="col-md-3">
                    <span class="text-muted small">Customer:</span>
                    <span class="fw-semibold">
                        @if ($invoice->customer){{ $invoice->customer->customer_name }}@else—@endif
                    </span>
                </div>
                <div class="col-md-3">
                    <span class="text-muted small">Branch:</span>
                    <span class="fw-semibold">
                        @if ($invoice->branch){{ $invoice->branch->branch_name }}@else—@endif
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- Left: COGS preview + transport form --}}
        <div class="col-lg-8">
            {{-- COGS preview card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-calculator me-1" style="color:#7c3aed;"></i> COGS preview
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
                                    <th>Product</th>
                                    <th>Warehouse</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Avg cost (Tk)</th>
                                    <th class="text-end">COGS amount (Tk)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($invoice->items as $item)
                                    <tr>
                                        <td>
                                            @if ($item->product)
                                                <div class="fw-semibold">{{ $item->product->product_name }}</div>
                                                <div class="small text-muted">{{ $item->product->product_code }}</div>
                                            @else
                                                <span class="text-muted">Product #{{ $item->product_id }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($item->warehouse)
                                                <span class="badge bg-secondary-subtle text-secondary">
                                                    {{ $item->warehouse->warehouse_name }}
                                                </span>
                                            @else
                                                <span class="badge bg-danger-subtle text-danger">— unassigned —</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ number_format((float) $item->qty, 2) }}</td>
                                        <td class="text-end">{{ number_format((float) ($item->avg_cost ?? 0), 2) }}</td>
                                        <td class="text-end fw-semibold">
                                            Tk {{ number_format((float) ($item->cogs_amount ?? 0), 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td colspan="4" class="text-end">Total COGS</td>
                                    <td class="text-end fs-5" style="color:#7c3aed;">
                                        Tk {{ number_format($totalCogs, 2) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Transport + notes form --}}
            <form method="POST" action="{{ route('admin.sales-challans.issueChallan', $invoice) }}" id="issueForm">
                @csrf

                {{-- R3: Idempotency token (UUID v4). Mirrors the finalize / payment
                     patterns. Generated server-side via Str::uuid() on first render;
                     preserved across validation failures via old() so the user can
                     resubmit the corrected form with the same token (safe — no
                     cache entry was created on the failed attempt). The server
                     caches the redirect target + success message keyed by this
                     token for 10 minutes so that a duplicate submission
                     (double-click, refresh-after-submit, network retry) returns
                     the original challan instead of throwing
                     "Challan already issued for this invoice." --}}
                <input type="hidden" name="idempotency_token" id="idempotencyToken"
                       value="{{ old('idempotency_token', (string) \Illuminate\Support\Str::uuid()) }}">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-truck me-1" style="color:#7c3aed;"></i> Transport details
                        </h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="transport_name">Transport name</label>
                                <input type="text" id="transport_name" name="transport_name"
                                       class="form-control" maxlength="100"
                                       value="{{ old('transport_name') }}" placeholder="e.g. Sundarban Paribahan">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="transport_phone">Transport phone</label>
                                <input type="text" id="transport_phone" name="transport_phone"
                                       class="form-control" maxlength="30"
                                       value="{{ old('transport_phone') }}" placeholder="01XXXXXXXXX">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="vehicle_number">Vehicle number</label>
                                <input type="text" id="vehicle_number" name="vehicle_number"
                                       class="form-control" maxlength="50"
                                       value="{{ old('vehicle_number') }}" placeholder="e.g. DHK-METRO-12-3456">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="driver_name">Driver name</label>
                                <input type="text" id="driver_name" name="driver_name"
                                       class="form-control" maxlength="100"
                                       value="{{ old('driver_name') }}" placeholder="Driver full name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="transport_cost">
                                    Transport cost (Tk)
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">Tk</span>
                                    <input type="number" step="0.01" min="0" id="transport_cost"
                                           name="transport_cost" class="form-control"
                                           value="{{ old('transport_cost', '0') }}">
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="notes">Notes</label>
                                <textarea id="notes" name="notes" class="form-control" rows="3"
                                          maxlength="500">{{ old('notes') }}</textarea>
                                <div class="form-text">Optional internal note (max 500 chars).</div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                        <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary btn-sm" id="issueBtn">
                            <i class="fas fa-paper-plane me-1"></i> Issue Challan
                        </button>
                    </div>
                </div>
            </form>
        </div>

        {{-- Right: summary sidebar --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sticky-top" style="top:80px;">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-clipboard-check me-1" style="color:#7c3aed;"></i> Issue summary
                    </h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-7 text-muted">Invoice</dt>
                        <dd class="col-5 text-end fw-semibold">{{ $invoice->invoice_code }}</dd>

                        <dt class="col-7 text-muted">Line items</dt>
                        <dd class="col-5 text-end">{{ $invoice->items->count() }}</dd>

                        <dt class="col-7 text-muted">Total COGS</dt>
                        <dd class="col-5 text-end fw-bold fs-6" style="color:#7c3aed;">
                            Tk {{ number_format($totalCogs, 2) }}
                        </dd>

                        <dt class="col-7 text-muted">Stock effect</dt>
                        <dd class="col-5 text-end">
                            <span class="badge bg-danger-subtle text-danger">
                                <i class="fas fa-minus me-1"></i>OUT
                            </span>
                        </dd>

                        <dt class="col-7 text-muted">GL effect</dt>
                        <dd class="col-5 text-end">
                            <span class="badge bg-info-subtle text-info">
                                Dr COGS / Cr Inventory
                            </span>
                        </dd>
                    </dl>
                    <hr>
                    <div class="alert alert-secondary small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        After issue, you will be redirected to the challan detail page where you can verify stock
                        movements and the GL journal entry.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    $('#issueForm').on('submit', function (e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Issue this challan?',
            html: '<p class="mb-1">This will <strong>move stock OUT</strong> and post a <strong>GL entry (Dr COGS / Cr Inventory)</strong>.</p>' +
                  '<p class="mb-0 text-muted small">Total COGS: Tk {{ number_format($totalCogs, 2) }}</p>',
            showCancelButton: true,
            confirmButtonColor: '#7c3aed',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-paper-plane"></i> Yes, issue challan',
            cancelButtonText: 'Cancel'
        }).then(function (res) {
            if (res.isConfirmed) {
                e.target.submit();
            }
        });
    });
});
</script>
@endpush
@endsection
