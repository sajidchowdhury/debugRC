@extends('layouts.admin')

@section('content')
@php
    $today   = now()->format('Y-m-d');
    $oldDate = old('damage_date', $today);
@endphp

<div class="container-fluid py-2">
    {{-- Hero header (red = loss) --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#dc2626,#b91c1c);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-triangle-exclamation me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Create a draft damage invoice — no stock movement or GL posting until you confirm.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.damages.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Warning banner --}}
    <div class="alert alert-danger d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-triangle-exclamation me-2 fa-lg mt-1"></i>
        <div>
            <strong>Damaged stock will be written off at current average cost.</strong>
            GL posts <span class="font-monospace">Dr Damage Loss / Cr Inventory</span>.
            Only confirmed damage invoices move stock and post to the ledger.
        </div>
    </div>

    <form method="POST" action="{{ route('admin.damages.store') }}" id="damageForm">
        @csrf

        {{-- Top: header fields --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-file-pen me-1 text-danger"></i> Damage header</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
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
                            Rate is auto-fetched from this warehouse's average cost.
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="damage_date">
                            Damage date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="damage_date" name="damage_date"
                               class="form-control @error('damage_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('damage_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="reason">Reason / note</label>
                        <textarea id="reason" name="reason" rows="2" class="form-control"
                                  placeholder="e.g. fire damage, water damage, expired, broken in transit">{{ old('reason') }}</textarea>
                        @error('reason') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- Items table --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">
                    <i class="fas fa-table-list me-1 text-danger"></i> Damaged items
                    <span class="badge bg-danger-subtle text-danger ms-1" id="itemCount">0</span>
                </h2>
                <button type="button" class="btn btn-sm btn-danger" id="addItemBtn">
                    <i class="fas fa-plus me-1"></i> Add item
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" id="itemsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:40%;">Product</th>
                                <th class="text-end" style="width:12%;">Qty</th>
                                <th class="text-end" style="width:13%;">Rate (Tk)</th>
                                <th class="text-end" style="width:13%;">Available</th>
                                <th class="text-end" style="width:15%;">Amount (Tk)</th>
                                <th class="text-center" style="width:7%;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            {{-- Rows injected by JS --}}
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="4" class="text-end">Total damage value</td>
                                <td class="text-end" id="totalAmount">0.00</td>
                                <td></td>
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
                <a href="{{ route('admin.damages.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-danger" id="submitBtn">
                    <i class="fas fa-file-pen me-1"></i> Create Draft Damage
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
    var productStockUrl = '{{ route("admin.damages.product-stock") }}';
    var $form        = $('#damageForm');
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

        // Qty input
        var $qty = $('<input>').attr({
            type: 'number',
            name: 'items[' + idx + '][qty]',
            class: 'form-control form-control-sm text-end qty-input',
            min: '0.001',
            step: '0.001',
            required: true,
            placeholder: '0.000'
        });

        // Rate input (auto-filled from AJAX; READONLY — avg cost is the only
        // valid rate for a damage write-off, matching legacy behavior. The
        // server re-validates and falls back to avg cost if rate <= 0.)
        var $rate = $('<input>').attr({
            type: 'number',
            name: 'items[' + idx + '][rate]',
            class: 'form-control form-control-sm text-end rate-input bg-light',
            min: '0',
            step: '0.01',
            readonly: true,
            placeholder: '0.00'
        });

        // Available (display only, from AJAX)
        var $avail = $('<input>').attr({
            type: 'text',
            class: 'form-control form-control-sm text-end available-input bg-light',
            readonly: true,
            placeholder: '—'
        });

        // Amount (display only)
        var $amt = $('<input>').attr({
            type: 'text',
            class: 'form-control form-control-sm text-end amount-input bg-light',
            readonly: true
        });
        $amt.val('0.00');

        // Remove button
        var $rm = $('<button>').attr({
            type: 'button',
            class: 'btn btn-sm btn-outline-danger remove-row',
            title: 'Remove item'
        }).html('<i class="fas fa-trash"></i>');

        $tr.append($tdProduct)
           .append($('<td class="text-end">').append($qty))
           .append($('<td class="text-end">').append($rate))
           .append($('<td class="text-end">').append($avail))
           .append($('<td class="text-end">').append($amt))
           .append($('<td class="text-center">').append($rm));

        $tbody.append($tr);

        // Initialize select2 on the new product select
        $sel.select2({ theme: 'bootstrap-5', width: '100%' });

        // Wire events
        $sel.on('select2:select', function () { onProductChange($tr); });
        $qty.on('input',  function () { recomputeRow($tr); });
        // Note: rate input is readonly (auto-fetched avg cost) — no input
        // listener needed.
        $rm.on('click',   function () {
            $tr.remove();
            recomputeTotal();
        });

        recomputeTotal();
        return $tr;
    }

    function onProductChange($tr) {
        var pid = parseInt($tr.find('.product-select').val(), 10);
        var wid = parseInt($warehouse.val(), 10);
        var $rate  = $tr.find('.rate-input');
        var $avail = $tr.find('.available-input');
        var $amt   = $tr.find('.amount-input');

        $avail.val('loading…');

        if (!pid || !wid) {
            $rate.val('');
            $avail.val('—');
            $amt.val('0.00');
            recomputeTotal();
            return;
        }

        $.ajax({
            url: productStockUrl,
            type: 'GET',
            data: { product_id: pid, warehouse_id: wid },
            dataType: 'json'
        }).done(function (data) {
            // Rate is readonly — just set the value (avg cost).
            $rate.val(Number(data.rate).toFixed(2));
            $avail.val(Number(data.available_qty).toFixed(4));
            recomputeRow($tr);
        }).fail(function () {
            $rate.val('');
            $avail.val('error');
            $amt.val('0.00');
            recomputeTotal();
        });
    }

    function recomputeRow($tr) {
        var qty  = parseFloat($tr.find('.qty-input').val())  || 0;
        var rate = parseFloat($tr.find('.rate-input').val()) || 0;
        var amt  = qty * rate;
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
                // No product yet — clear rate/available
                $tr.find('.rate-input').val('');
                $tr.find('.available-input').val('—');
                $tr.find('.amount-input').val('0.00');
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
            // Re-fetch rate/available if both product & warehouse are set
            if (item.product_id && $warehouse.val()) {
                onProductChange($tr);
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
                text: 'Add at least one product line before creating the damage invoice.',
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
