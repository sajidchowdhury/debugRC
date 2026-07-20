@extends('layouts.admin')

@section('content')
@php
    $oldDate  = old('po_date', optional($po->po_date)->format('Y-m-d') ?? (string) $po->po_date);
    $oldExp   = old('expected_date', $po->expected_date ? ($po->expected_date->format('Y-m-d') ?? (string) $po->expected_date) : '');
    $oldSup   = old('supplier_id', $po->supplier_id);
    $oldBr    = old('branch_id', $po->branch_id);
    $oldWh    = old('warehouse_id', $po->warehouse_id);
    $oldDisc  = old('discount_amount', $po->discount_amount);
    $oldTax   = old('tax_amount', $po->tax_amount);
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#2563eb,#1d4ed8);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-pen-to-square me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Editing draft PO <strong>{{ $po->po_code }}</strong> — no stock movement or GL posting until GRN.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.purchase-orders.show', $po) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-eye me-1"></i> View
            </a>
            <a href="{{ route('admin.purchase-orders.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Info banner --}}
    <div class="alert alert-warning d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-triangle-exclamation me-2 mt-1"></i>
        <div>
            <strong>Editing a draft PO.</strong>
            Once you <em>Mark as Sent</em>, the PO becomes immutable. Stock movement and GL posting
            happen later via <em>GRN</em> (Phase 7.2).
        </div>
    </div>

    <form method="POST" action="{{ route('admin.purchase-orders.update', $po) }}" id="poForm">
        @csrf
        @method('PUT')

        {{-- Header fields --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-sliders me-1 text-primary"></i> PO header</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="supplier_id">
                            Supplier <span class="text-danger">*</span>
                        </label>
                        <select id="supplier_id" name="supplier_id"
                                class="form-select select2 @error('supplier_id') is-invalid @enderror" required>
                            <option value="">Select supplier</option>
                            @foreach ($suppliers as $s)
                                <option value="{{ $s->id }}"
                                    {{ (string) $oldSup === (string) $s->id ? 'selected' : '' }}>
                                    {{ $s->supplier_code }} — {{ $s->supplier_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('supplier_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="branch_id">
                            Branch <span class="text-danger">*</span>
                        </label>
                        <select id="branch_id" name="branch_id"
                                class="form-select select2 @error('branch_id') is-invalid @enderror" required>
                            <option value="">Select branch</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}"
                                    {{ (string) $oldBr === (string) $b->id ? 'selected' : '' }}>
                                    {{ $b->branch_code }} — {{ $b->branch_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="warehouse_id">
                            Warehouse
                            <span class="text-muted small">(where goods will be received)</span>
                        </label>
                        <select id="warehouse_id" name="warehouse_id"
                                class="form-select select2 @error('warehouse_id') is-invalid @enderror">
                            <option value="">— optional —</option>
                            @foreach ($warehouses as $wh)
                                <option value="{{ $wh->id }}"
                                    {{ (string) $oldWh === (string) $wh->id ? 'selected' : '' }}>
                                    {{ $wh->warehouse_code }} — {{ $wh->warehouse_name }}
                                    @if ($wh->branch) ({{ $wh->branch->branch_name }}) @endif
                                </option>
                            @endforeach
                        </select>
                        @error('warehouse_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="po_date">
                            PO date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="po_date" name="po_date"
                               class="form-control @error('po_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('po_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="expected_date">
                            Expected delivery date
                            <span class="text-muted small">(optional)</span>
                        </label>
                        <input type="date" id="expected_date" name="expected_date"
                               class="form-control @error('expected_date') is-invalid @enderror"
                               value="{{ $oldExp }}">
                        @error('expected_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="2" class="form-control"
                                  placeholder="Internal notes — payment terms, delivery instructions, etc.">{{ old('notes', $po->notes) }}</textarea>
                        @error('notes') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
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
                                <th style="width:45%;">Product</th>
                                <th class="text-end" style="width:12%;">Qty</th>
                                <th class="text-end" style="width:13%;">Rate (Tk)</th>
                                <th class="text-end" style="width:15%;">Amount (Tk)</th>
                                <th class="text-center" style="width:5%;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            {{-- Rows injected by JS --}}
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="3" class="text-end">Sub-total</td>
                                <td class="text-end" id="subTotal">0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="p-3">
                    <div class="text-danger small d-none" id="itemsError">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        Add at least one item with a product and qty &gt; 0.
                    </div>
                </div>
            </div>
        </div>

        {{-- Totals --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-calculator me-1 text-primary"></i> Totals</h2>
            </div>
            <div class="card-body">
                <div class="row g-3 justify-content-end">
                    <div class="col-md-3">
                        <label class="form-label" for="discount_amount">Discount amount (Tk)</label>
                        <input type="number" id="discount_amount" name="discount_amount"
                               class="form-control text-end @error('discount_amount') is-invalid @enderror"
                               min="0" step="0.01" value="{{ $oldDisc }}"
                               placeholder="0.00">
                        @error('discount_amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="tax_amount">Tax amount (Tk)</label>
                        <input type="number" id="tax_amount" name="tax_amount"
                               class="form-control text-end @error('tax_amount') is-invalid @enderror"
                               min="0" step="0.01" value="{{ $oldTax }}"
                               placeholder="0.00">
                        @error('tax_amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-muted">Sub-total (Tk)</label>
                        <input type="text" id="subTotalDisplay"
                               class="form-control text-end bg-light fw-semibold" readonly value="0.00">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Total amount (Tk)</label>
                        <input type="text" id="totalAmountDisplay"
                               class="form-control text-end bg-primary-subtle text-primary fw-bold fs-5" readonly value="0.00">
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex gap-2 justify-content-end">
                <a href="{{ route('admin.purchase-orders.show', $po) }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-floppy-disk me-1"></i> Update PO
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
    var $form         = $('#poForm');
    var $tbody        = $('#itemsBody');
    var $subTotal     = $('#subTotal');
    var $subDisplay   = $('#subTotalDisplay');
    var $totalDisplay = $('#totalAmountDisplay');
    var $discount     = $('#discount_amount');
    var $tax          = $('#tax_amount');
    var $itemCount    = $('#itemCount');
    var $itemsError   = $('#itemsError');
    var rowIndex      = 0;

    // Init select2 on header selects
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    // ====== Item row helpers ======

    function buildRow(idx) {
        var productOpts = $($('#productOptionsTpl').html()).clone();
        var $tr = $('<tr>').attr('data-row', idx);

        // Product select
        var $sel = $('<select>').attr({
            name: 'items[' + idx + '][product_id]',
            class: 'form-select form-select-sm product-select',
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

        // Rate input
        var $rate = $('<input>').attr({
            type: 'number',
            name: 'items[' + idx + '][rate]',
            class: 'form-control form-control-sm text-end rate-input',
            min: '0',
            step: '0.01',
            required: true,
            placeholder: '0.00'
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
           .append($('<td class="text-end">').append($amt))
           .append($('<td class="text-center">').append($rm));

        $tbody.append($tr);

        // Initialize select2 on the new product select
        $sel.select2({ theme: 'bootstrap-5', width: '100%' });

        // Wire events
        $qty.on('input',  function () { recomputeRow($tr); });
        $rate.on('input', function () { recomputeRow($tr); });
        $rm.on('click',   function () {
            $tr.remove();
            recomputeTotal();
        });

        recomputeTotal();
        return $tr;
    }

    function recomputeRow($tr) {
        var qty  = parseFloat($tr.find('.qty-input').val())  || 0;
        var rate = parseFloat($tr.find('.rate-input').val()) || 0;
        var amt  = qty * rate;
        $tr.find('.amount-input').val(amt.toFixed(2));
        recomputeTotal();
    }

    function recomputeTotal() {
        var subTotal = 0;
        var rows = 0;
        $tbody.find('tr').each(function () {
            var amt = parseFloat($(this).find('.amount-input').val()) || 0;
            subTotal += amt;
            rows++;
        });
        var discount = parseFloat($discount.val()) || 0;
        var tax      = parseFloat($tax.val())      || 0;
        var total    = subTotal - discount + tax;

        $subTotal.text(subTotal.toFixed(2));
        $subDisplay.val(subTotal.toFixed(2));
        $totalDisplay.val(total.toFixed(2));
        $itemCount.text(rows);
        $itemsError.toggleClass('d-none', rows > 0);
    }

    // ====== Add item button ======
    $('#addItemBtn').on('click', function () {
        buildRow(rowIndex++);
    });

    // ====== Discount / tax input ======
    $discount.on('input', recomputeTotal);
    $tax.on('input',      recomputeTotal);

    // ====== Pre-populate from old input (on validation error) or from $po ======
    var seedItems = null;
    @if (old('items'))
        seedItems = @json(old('items'));
    @else
        seedItems = @json($po->items->map(function ($i) {
            return [
                'product_id' => (int) $i->product_id,
                'qty'        => (float) $i->qty,
                'rate'       => (float) $i->rate,
            ];
        })->values());
    @endif

    if (Array.isArray(seedItems) && seedItems.length > 0) {
        seedItems.forEach(function (item) {
            var $tr = buildRow(rowIndex++);
            if (item.product_id) {
                $tr.find('.product-select').val(item.product_id).trigger('change');
            }
            if (item.qty)  $tr.find('.qty-input').val(item.qty);
            if (item.rate) $tr.find('.rate-input').val(item.rate);
            recomputeRow($tr);
        });
    } else {
        // Seed with one empty row by default
        buildRow(rowIndex++);
    }

    // ====== Submit guard ======
    $form.on('submit', function (e) {
        var rows = $tbody.find('tr').length;
        if (rows === 0) {
            e.preventDefault();
            $itemsError.removeClass('d-none');
            Swal.fire({
                icon: 'error',
                title: 'No items',
                text: 'Add at least one product line before saving the PO.',
                confirmButtonText: 'OK'
            });
            return false;
        }
        // Validate each row has product + qty
        var invalid = 0;
        $tbody.find('tr').each(function () {
            var pid = $(this).find('.product-select').val();
            var qty = parseFloat($(this).find('.qty-input').val());
            var rate = parseFloat($(this).find('.rate-input').val());
            if (!pid || !qty || qty <= 0 || isNaN(rate)) invalid++;
        });
        if (invalid > 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Incomplete items',
                text: invalid + ' row(s) are missing a product, qty, or rate. Please fix or remove them.',
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
