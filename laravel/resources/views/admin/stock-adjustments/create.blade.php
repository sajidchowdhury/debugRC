@extends('layouts.admin')

@section('content')
@php
    $today = now()->format('Y-m-d');
    $oldType = old('adjustment_type', 'increase');
    $oldDate = old('adjustment_date', $today);
    // Phase 2 — default to 'other' so the form is valid without explicit choice,
    // but the dropdown is rendered with all 7 categories so the user is nudged
    // to pick the most accurate one.
    $oldCategory = old('adjustment_category', 'other');
    $categoryLabels = $categoryLabels ?? \App\Models\StockAdjustment::CATEGORY_LABELS;
    $categories     = $categories     ?? \App\Models\StockAdjustment::ADJUSTMENT_CATEGORIES;
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#0891b2);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-plus me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Create a draft adjustment — no stock movement or GL posting until you confirm.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.stock-adjustments.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    <form method="POST" action="{{ route('admin.stock-adjustments.store') }}" id="adjustmentForm">
        @csrf

        {{-- Top: header fields --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-sliders me-1 text-primary"></i> Adjustment header</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="warehouse_id">
                            Warehouse <span class="text-danger">*</span>
                        </label>
                        <select id="warehouse_id" name="warehouse_id"
                                class="form-select select2 @error('warehouse_id') is-invalid @enderror" required>
                            <option value="">Select warehouse</option>
                            @foreach ($warehouses as $wh)
                                <option value="{{ $wh->id }}"
                                    {{ (string) old('warehouse_id') === (string) $wh->id ? 'selected' : '' }}>
                                    {{ $wh->warehouse_code }} — {{ $wh->warehouse_name }}
                                    @if ($wh->branch) ({{ $wh->branch->branch_name }}) @endif
                                </option>
                            @endforeach
                        </select>
                        @error('warehouse_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text small">
                            <i class="fas fa-circle-info me-1"></i>
                            Stock rate is fetched from this warehouse's average cost.
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label d-block">Adjustment type <span class="text-danger">*</span></label>
                        <div class="btn-group w-100" role="group" id="typeGroup">
                            <input type="radio" class="btn-check" name="adjustment_type" id="type_increase"
                                   value="increase" autocomplete="off" {{ $oldType === 'increase' ? 'checked' : '' }}>
                            <label class="btn btn-outline-success" for="type_increase">
                                <i class="fas fa-arrow-up me-1"></i> Increase
                            </label>

                            <input type="radio" class="btn-check" name="adjustment_type" id="type_decrease"
                                   value="decrease" autocomplete="off" {{ $oldType === 'decrease' ? 'checked' : '' }}>
                            <label class="btn btn-outline-danger" for="type_decrease">
                                <i class="fas fa-arrow-down me-1"></i> Decrease
                            </label>
                        </div>
                        @error('adjustment_type') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="adjustment_date">
                            Adjustment date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="adjustment_date" name="adjustment_date"
                               class="form-control @error('adjustment_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('adjustment_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Phase 2 — Adjustment category dropdown --}}
                    <div class="col-md-3">
                        <label class="form-label" for="adjustment_category">
                            Category <span class="text-danger">*</span>
                        </label>
                        <select id="adjustment_category" name="adjustment_category"
                                class="form-select select2 @error('adjustment_category') is-invalid @enderror" required>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat }}" {{ $oldCategory === $cat ? 'selected' : '' }}>
                                    {{ $categoryLabels[$cat] ?? ucfirst(str_replace('_', ' ', $cat)) }}
                                </option>
                            @endforeach
                        </select>
                        @error('adjustment_category') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text small">
                            <i class="fas fa-tag me-1"></i>
                            Opening-balance rows are tagged <code>reference_type=opening_balance</code> in the ledger.
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="reason">Reason / note</label>
                        <textarea id="reason" name="reason" rows="2" class="form-control"
                                  placeholder="Why this adjustment? e.g. Annual stocktake variance, damaged goods.">{{ old('reason') }}</textarea>
                        @error('reason') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>

                    {{-- Phase 3 — approval-policy hint so the drafter knows the workflow up front --}}
                    @if (!empty($approvalHint))
                        <div class="col-12">
                            <div class="alert alert-info small mb-0 py-2">
                                <i class="fas fa-circle-info me-1"></i>
                                <strong>Approval workflow:</strong> {{ $approvalHint }}
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Items table --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">
                    <i class="fas fa-table-list me-1 text-primary"></i> Items
                    <span class="badge bg-primary-subtle text-primary ms-1" id="itemCount">0</span>
                </h2>
                <button type="button" class="btn btn-sm btn-primary" id="addItemBtn">
                    <i class="fas fa-plus me-1"></i> Add item
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" id="itemsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:26%;">Product</th>
                                <th class="text-end" style="width:8%;">Qty</th>
                                <th style="width:11%;">UOM</th>
                                <th class="text-end" style="width:10%;">Base qty</th>
                                <th class="text-end" style="width:9%;">Rate (Tk)</th>
                                <th class="text-end" style="width:9%;">Available</th>
                                <th class="text-end" style="width:11%;">Amount (Tk)</th>
                                <th style="width:14%;">Reason</th>
                                <th class="text-center" style="width:2%;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            {{-- Rows injected by JS --}}
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="6" class="text-end">Total</td>
                                <td class="text-end" id="totalAmount">0.00</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="p-3">
                    <div class="text-danger small d-none" id="itemsError">
                        <i class="fas fa-exclamation-circle me-1"></i> Add at least one item with a product and qty &gt; 0.
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex gap-2 justify-content-end">
                <a href="{{ route('admin.stock-adjustments.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-success" id="submitBtn">
                    <i class="fas fa-file-pen me-1"></i> Create Draft Adjustment
                </button>
            </div>
        </div>
    </form>
</div>

{{-- Hidden product options template (rendered server-side for select2 to use) --}}
<template id="productOptionsTpl">
    <option value="">Select product</option>
    @foreach ($products as $p)
        <option value="{{ $p->id }}">{{ $p->product_code }} — {{ $p->product_name }}</option>
    @endforeach
</template>

@push('scripts')
<script>
$(function () {
    var productRateUrl = '{{ route("admin.stock-adjustments.product-rate") }}';
    var productUomsUrl = '{{ route("admin.stock-adjustments.product-uoms") }}';
    var $form        = $('#adjustmentForm');
    var $warehouse   = $('#warehouse_id');
    var $tbody       = $('#itemsBody');
    var $totalAmount = $('#totalAmount');
    var $itemCount   = $('#itemCount');
    var $itemsError  = $('#itemsError');
    var rowIndex     = 0;

    // Init select2 on header selects
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    // ====== Item row helpers ======

    function buildRow(idx) {
        var productOpts = $($('#productOptionsTpl').html()).clone();
        var $tr = $('<tr>').attr('data-row', idx);

        // Product select
        var $sel = $('<select>').attr({
            name: 'items[' + idx + '][product_id]',
            class: 'form-select form-select-sm select2-row product-select',
            required: true
        }).append(productOpts);

        var $tdProduct = $('<td>').append($sel);

        // Qty input (the qty ENTERED in the selected UOM)
        var $qty = $('<input>').attr({
            type: 'number',
            name: 'items[' + idx + '][qty]',
            class: 'form-control form-control-sm text-end qty-input',
            min: '0.001',
            step: '0.001',
            required: true,
            placeholder: '0.000'
        });

        // Phase 5 — UOM dropdown (populated via AJAX on product select).
        // Holds the available UOMs for the selected product + their factors.
        var $uom = $('<select>').attr({
            name: 'items[' + idx + '][uom_id]',
            class: 'form-select form-select-sm uom-select',
            'data-factor': '1'
        }).append($('<option>').val('').text('— unit —'));

        // Phase 5 — Base qty (read-only display = qty × factor)
        var $baseQty = $('<input>').attr({
            type: 'text',
            class: 'form-control form-control-sm text-end base-qty-input bg-light',
            readonly: true,
            placeholder: '0.000'
        });

        // Rate input (per BASE unit)
        var $rate = $('<input>').attr({
            type: 'number',
            name: 'items[' + idx + '][rate]',
            class: 'form-control form-control-sm text-end rate-input',
            min: '0',
            step: '0.01',
            placeholder: '0.00'
        });

        // Available (display only)
        var $avail = $('<input>').attr({
            type: 'text',
            class: 'form-control form-control-sm text-end available-input bg-light',
            readonly: true,
            placeholder: '—'
        });

        // Amount (display only) = qty_base × rate
        var $amt = $('<input>').attr({
            type: 'text',
            class: 'form-control form-control-sm text-end amount-input bg-light',
            readonly: true
        });
        $amt.val('0.00');

        // Reason (per line)
        var $reason = $('<input>').attr({
            type: 'text',
            name: 'items[' + idx + '][reason]',
            class: 'form-control form-control-sm reason-input',
            maxlength: 500,
            placeholder: 'optional'
        });

        // Remove button
        var $rm = $('<button>').attr({
            type: 'button',
            class: 'btn btn-sm btn-outline-danger remove-row',
            title: 'Remove item'
        }).html('<i class="fas fa-trash"></i>');

        $tr.append($tdProduct)
           .append($('<td class="text-end">').append($qty))
           .append($('<td>').append($uom))
           .append($('<td class="text-end">').append($baseQty))
           .append($('<td class="text-end">').append($rate))
           .append($('<td class="text-end">').append($avail))
           .append($('<td class="text-end">').append($amt))
           .append($('<td>').append($reason))
           .append($('<td class="text-center">').append($rm));

        $tbody.append($tr);

        // Initialize select2 on the new product select
        $sel.select2({ theme: 'bootstrap-5', width: '100%' });

        // Wire events
        $sel.on('select2:select', function () { onProductChange($tr); });
        $qty.on('input',  function () { recomputeRow($tr); });
        $rate.on('input', function () { recomputeRow($tr); });
        $uom.on('change', function () { onUomChange($tr); });
        $rm.on('click',   function () {
            $tr.remove();
            recomputeTotal();
        });

        recomputeTotal();
        return $tr;
    }

    // Phase 5 — populate the UOM dropdown for a product + default to base.
    function loadUoms($tr, pid, selectedUomId) {
        var $uom = $tr.find('.uom-select');
        $uom.prop('disabled', true);
        if (!pid) {
            $uom.empty().append($('<option>').val('').text('— unit —'))
                .attr('data-factor', '1').prop('disabled', false);
            recomputeRow($tr);
            return;
        }
        $.ajax({
            url: productUomsUrl,
            type: 'GET',
            data: { product_id: pid },
            dataType: 'json'
        }).done(function (data) {
            $uom.empty();
            if (!data.uoms || data.uoms.length === 0) {
                // No base unit found (product.unit not in units_of_measure).
                // Fall back to a single "base" option so the form still submits.
                $uom.append($('<option>').val('').text('base'));
                $uom.attr('data-factor', '1');
            } else {
                data.uoms.forEach(function (u) {
                    var $opt = $('<option>')
                        .val(u.uom_id)
                        .text(u.code + (u.is_base ? ' (base)' : ''))
                        .attr('data-factor', u.factor);
                    if (selectedUomId && String(selectedUomId) === String(u.uom_id)) {
                        $opt.attr('selected', 'selected');
                    } else if (u.is_base && !selectedUomId) {
                        $opt.attr('selected', 'selected');
                    }
                    $uom.append($opt);
                });
                // Sync the row-level factor from the selected option.
                var $selOpt = $uom.find('option:selected');
                $uom.attr('data-factor', $selOpt.attr('data-factor') || '1');
            }
            $uom.prop('disabled', false);
            recomputeRow($tr);
        }).fail(function () {
            $uom.empty().append($('<option>').val('').text('error'));
            $uom.attr('data-factor', '1').prop('disabled', false);
            recomputeRow($tr);
        });
    }

    // Phase 5 — when the UOM dropdown changes, sync the factor + recompute.
    function onUomChange($tr) {
        var $uom = $tr.find('.uom-select');
        var factor = parseFloat($uom.find('option:selected').attr('data-factor')) || 1;
        $uom.attr('data-factor', factor);
        recomputeRow($tr);
    }

    function onProductChange($tr) {
        var pid = parseInt($tr.find('.product-select').val(), 10);
        var wid = parseInt($warehouse.val(), 10);
        var $rate  = $tr.find('.rate-input');
        var $avail = $tr.find('.available-input');
        var $amt   = $tr.find('.amount-input');

        // Phase 5 — always (re)load the UOM dropdown for this product.
        loadUoms($tr, pid, null);

        $rate.prop('disabled', true);
        $avail.val('loading…');

        if (!pid || !wid) {
            $rate.val('').prop('disabled', false);
            $avail.val('—');
            $amt.val('0.00');
            recomputeTotal();
            return;
        }

        $.ajax({
            url: productRateUrl,
            type: 'GET',
            data: { product_id: pid, warehouse_id: wid },
            dataType: 'json'
        }).done(function (data) {
            $rate.val(Number(data.rate).toFixed(2)).prop('disabled', false);
            $avail.val(Number(data.available_qty).toFixed(4));
            recomputeRow($tr);
        }).fail(function () {
            $rate.val('').prop('disabled', false);
            $avail.val('error');
            $amt.val('0.00');
            recomputeTotal();
        });
    }

    function recomputeRow($tr) {
        var qty   = parseFloat($tr.find('.qty-input').val())  || 0;
        var factor = parseFloat($tr.find('.uom-select').attr('data-factor')) || 1;
        var rate  = parseFloat($tr.find('.rate-input').val()) || 0;
        // Phase 5 — base qty = entered qty × factor. Amount = base qty × rate.
        var baseQty = qty * factor;
        var amt = baseQty * rate;
        $tr.find('.base-qty-input').val(baseQty.toFixed(4));
        $tr.find('.amount-input').val(amt.toFixed(2));
        recomputeTotal();
    }

    function recomputeTotal() {
        var total = 0;
        var rows  = 0;
        $tbody.find('tr').each(function () {
            var amt = parseFloat($(this).find('.amount-input').val()) || 0;
            total += amt;
            rows++;
        });
        // #totalAmount is a <td>, so use .text() not .val()
        $totalAmount.text(total.toFixed(2));
        $itemCount.text(rows);
        $itemsError.toggleClass('d-none', rows > 0);
    }

    // ====== Add item button ======
    $('#addItemBtn').on('click', function () {
        buildRow(rowIndex++);
    });

    // ====== When warehouse changes: refresh rates for all rows ======
    $warehouse.on('change', function () {
        $tbody.find('tr').each(function () {
            var $tr = $(this);
            if ($tr.find('.product-select').val()) {
                onProductChange($tr);
            } else {
                // No product yet — clear rate/available/base-qty
                $tr.find('.rate-input').val('');
                $tr.find('.available-input').val('—');
                $tr.find('.amount-input').val('0.00');
                $tr.find('.base-qty-input').val('');
            }
        });
        recomputeTotal();
    });

    // ====== Pre-populate old input on validation errors ======
    @if (old('items'))
        var oldItems = @json(old('items'));
        oldItems.forEach(function (item) {
            var $tr = buildRow(rowIndex++);
            if (item.product_id) {
                $tr.find('.product-select').val(item.product_id).trigger('change');
            }
            if (item.qty)  $tr.find('.qty-input').val(item.qty);
            if (item.rate) $tr.find('.rate-input').val(item.rate);
            if (item.reason) $tr.find('.reason-input').val(item.reason);
            // Re-fetch rate/available + UOMs if product is set.
            if (item.product_id && $warehouse.val()) {
                onProductChange($tr);
                // After the AJAX UOM load, re-apply the old uom_id selection.
                if (item.uom_id) {
                    // loadUoms is async; wait for it via a small timeout.
                    setTimeout(function () {
                        $tr.find('.uom-select').val(item.uom_id).trigger('change');
                    }, 400);
                }
            } else if (item.product_id) {
                loadUoms($tr, item.product_id, item.uom_id || null);
                recomputeRow($tr);
            } else {
                recomputeRow($tr);
            }
        });
    @else
        // Seed with one empty row by default
        buildRow(rowIndex++);
    @endif

    // ====== Submit guard ======
    $form.on('submit', function (e) {
        var rows = $tbody.find('tr').length;
        if (rows === 0) {
            e.preventDefault();
            $itemsError.removeClass('d-none');
            Swal.fire({
                icon: 'error',
                title: 'No items',
                text: 'Add at least one product line before creating the adjustment.',
                confirmButtonText: 'OK'
            });
            return false;
        }
        // Validate each row has product + qty
        var invalid = 0;
        $tbody.find('tr').each(function () {
            var pid = $(this).find('.product-select').val();
            var qty = parseFloat($(this).find('.qty-input').val());
            if (!pid || !qty || qty <= 0) invalid++;
        });
        if (invalid > 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Incomplete items',
                text: invalid + ' row(s) are missing a product or have qty ≤ 0. Please fix or remove them.',
                confirmButtonText: 'OK'
            });
            return false;
        }
        $('#submitBtn').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-1"></i> Saving…');
    });
});
</script>
@endpush
@endsection
