@extends('layouts.admin')

@section('content')
@php
    $today    = now()->format('Y-m-d');
    $oldDate  = old('transfer_date', $today);
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#ea580c,#d97706);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-plus me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Create a draft transfer — no stock moves or GL posting until you confirm.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.warehouse-transfers.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    <form method="POST" action="{{ route('admin.warehouse-transfers.store') }}" id="transferForm">
        @csrf

        {{-- Top: header fields --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-sliders me-1 text-primary"></i> Transfer header</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="from_warehouse_id">
                            From warehouse <span class="text-danger">*</span>
                        </label>
                        <select id="from_warehouse_id" name="from_warehouse_id"
                                class="form-select select2 @error('from_warehouse_id') is-invalid @enderror" required>
                            <option value="">Select source warehouse</option>
                            @foreach ($warehouses as $wh)
                                <option value="{{ $wh->id }}"
                                        data-branch-id="{{ $wh->branch_id ?? '' }}"
                                        data-branch-name="{{ $wh->branch?->branch_name ?? '' }}"
                                    {{ (string) old('from_warehouse_id') === (string) $wh->id ? 'selected' : '' }}>
                                    {{ $wh->warehouse_code }} — {{ $wh->warehouse_name }}
                                    @if ($wh->branch) ({{ $wh->branch->branch_name }}) @endif
                                </option>
                            @endforeach
                        </select>
                        @error('from_warehouse_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text small">
                            <i class="fas fa-circle-info me-1"></i>
                            Stock is fetched from this warehouse (source OUT). Rate auto-fills from avg cost.
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="to_warehouse_id">
                            To warehouse <span class="text-danger">*</span>
                        </label>
                        <select id="to_warehouse_id" name="to_warehouse_id"
                                class="form-select select2 @error('to_warehouse_id') is-invalid @enderror" required>
                            <option value="">Select destination warehouse</option>
                            @foreach ($warehouses as $wh)
                                <option value="{{ $wh->id }}"
                                        data-branch-id="{{ $wh->branch_id ?? '' }}"
                                        data-branch-name="{{ $wh->branch?->branch_name ?? '' }}"
                                    {{ (string) old('to_warehouse_id') === (string) $wh->id ? 'selected' : '' }}>
                                    {{ $wh->warehouse_code }} — {{ $wh->warehouse_name }}
                                    @if ($wh->branch) ({{ $wh->branch->branch_name }}) @endif
                                </option>
                            @endforeach
                        </select>
                        @error('to_warehouse_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text small">
                            <i class="fas fa-circle-info me-1"></i>
                            Must be a different warehouse. Stock arrives here (destination IN).
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="transfer_date">
                            Transfer date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="transfer_date" name="transfer_date"
                               class="form-control @error('transfer_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('transfer_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="2" class="form-control"
                                  placeholder="Optional context for this transfer.">{{ old('notes') }}</textarea>
                        @error('notes') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>

                {{-- Interbranch detection banner --}}
                <div class="alert alert-info d-flex align-items-center mt-3 mb-0 d-none" id="interbranchBanner">
                    <i class="fas fa-arrow-right-arrow-left me-2 fa-lg"></i>
                    <div>
                        <strong>This is an interbranch transfer.</strong>
                        On confirm, intercompany GL will be posted
                        (<span id="fromBranchName">—</span> → <span id="toBranchName">—</span>):
                        <em>Due-to-Branch</em> / <em>Due-from-Branch</em> ledgers track the settlement.
                    </div>
                </div>
                <div class="alert alert-secondary d-flex align-items-center mt-3 mb-0 d-none" id="sameBranchBanner">
                    <i class="fas fa-warehouse me-2 fa-lg"></i>
                    <div>
                        <strong>Same-branch transfer.</strong>
                        Stock is reallocated within the same branch — no intercompany GL will be posted.
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
                                <th style="width:38%;">Product</th>
                                <th class="text-end" style="width:12%;">Qty</th>
                                <th class="text-end" style="width:12%;">Rate (Tk)</th>
                                <th class="text-end" style="width:13%;">Available</th>
                                <th class="text-end" style="width:15%;">Amount (Tk)</th>
                                <th class="text-center" style="width:10%;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            {{-- Rows injected by JS --}}
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="4" class="text-end">Total</td>
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
                <a href="{{ route('admin.warehouse-transfers.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-success" id="submitBtn">
                    <i class="fas fa-file-pen me-1"></i> Create Draft Transfer
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
    var productStockUrl = '{{ route("admin.warehouse-transfers.product-stock") }}';
    var $form           = $('#transferForm');
    var $fromWh         = $('#from_warehouse_id');
    var $toWh           = $('#to_warehouse_id');
    var $tbody          = $('#itemsBody');
    var $totalAmount    = $('#totalAmount');
    var $itemCount      = $('#itemCount');
    var $itemsError     = $('#itemsError');
    var $interBanner    = $('#interbranchBanner');
    var $sameBanner     = $('#sameBranchBanner');
    var $fromBranchName = $('#fromBranchName');
    var $toBranchName   = $('#toBranchName');
    var rowIndex        = 0;

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

        // Rate input
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
        $rate.on('input', function () { recomputeRow($tr); });
        $rm.on('click',   function () {
            $tr.remove();
            recomputeTotal();
        });

        recomputeTotal();
        return $tr;
    }

    function onProductChange($tr) {
        var pid = parseInt($tr.find('.product-select').val(), 10);
        var wid = parseInt($fromWh.val(), 10);
        var $rate  = $tr.find('.rate-input');
        var $avail = $tr.find('.available-input');
        var $amt   = $tr.find('.amount-input');

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
            url: productStockUrl,
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

    // ====== When FROM warehouse changes: refresh stock/rate for all rows ======
    $fromWh.on('change', function () {
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
        refreshInterbranchBanner();
    });

    // ====== When TO warehouse changes: just refresh the interbranch banner ======
    $toWh.on('change', function () {
        refreshInterbranchBanner();
    });

    // ====== Interbranch detection (compares branch_id data attribute) ======
    function refreshInterbranchBanner() {
        var $fromOpt = $fromWh.find('option:selected');
        var $toOpt   = $toWh.find('option:selected');
        var fromBranId = $fromOpt.attr('data-branch-id');
        var toBranId   = $toOpt.attr('data-branch-id');

        // Hide both first
        $interBanner.addClass('d-none');
        $sameBanner.addClass('d-none');

        if (!fromBranId || !toBranId) {
            return; // One of the warehouses not selected yet
        }

        if (fromBranId !== toBranId) {
            $fromBranchName.text($fromOpt.attr('data-branch-name') || 'from-branch');
            $toBranchName.text($toOpt.attr('data-branch-name') || 'to-branch');
            $interBanner.removeClass('d-none');
        } else {
            $sameBanner.removeClass('d-none');
        }
    }

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
            if (item.product_id && $fromWh.val()) {
                onProductChange($tr);
            } else {
                recomputeRow($tr);
            }
        });
    @else
        // Seed with one empty row by default
        buildRow(rowIndex++);
    @endif

    // Initial banner state in case old() repopulates both warehouses
    refreshInterbranchBanner();

    // ====== Submit guard ======
    $form.on('submit', function (e) {
        // 1. From != To warehouse
        var fromId = parseInt($fromWh.val(), 10);
        var toId   = parseInt($toWh.val(), 10);
        if (!fromId || !toId) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Warehouses required',
                text: 'Please select both a source and a destination warehouse.',
                confirmButtonText: 'OK'
            });
            return false;
        }
        if (fromId === toId) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Same warehouse',
                text: 'The source and destination warehouses must be different.',
                confirmButtonText: 'OK'
            });
            return false;
        }

        // 2. At least one item row
        var rows = $tbody.find('tr').length;
        if (rows === 0) {
            e.preventDefault();
            $itemsError.removeClass('d-none');
            Swal.fire({
                icon: 'error',
                title: 'No items',
                text: 'Add at least one product line before creating the transfer.',
                confirmButtonText: 'OK'
            });
            return false;
        }

        // 3. Each row has product + qty > 0
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
