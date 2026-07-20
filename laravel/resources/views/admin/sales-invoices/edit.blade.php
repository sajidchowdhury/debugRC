@extends('layouts.admin')

@section('content')
@php
    $branchName = session('branch_name', 'No Branch');
@endphp

<div class="container-fluid py-2" id="editInvoiceApp">

    {{-- Header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background:linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-pen-to-square me-2"></i>Edit Invoice
                <span class="badge bg-light text-dark ms-2">{{ $invoice->invoice_code }}</span>
            </h1>
            <p class="mb-0 opacity-75">
                <i class="fas fa-user me-1"></i> {{ $invoice->customer?->customer_name ?? '—' }}
                <span class="mx-2 opacity-50">•</span>
                <i class="fas fa-building me-1"></i> {{ $branchName }}
                <span class="mx-2 opacity-50">•</span>
                <span class="badge bg-warning text-dark">Draft</span>
            </p>
        </div>
        <div class="d-flex gap-1">
            <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to Invoice
            </a>
        </div>
    </header>

    {{-- Error/success alerts --}}
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-triangle-exclamation me-1"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-circle-check me-1"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.sales-invoices.update', $invoice) }}" id="editInvoiceForm">
        @csrf
        @method('PUT')

        <div class="row g-3">
            {{-- Left column: items table --}}
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-list me-1" style="color:#7c3aed;"></i> Invoice Items
                        </h2>
                        <button type="button" class="btn btn-sm btn-primary" id="btnAddItem">
                            <i class="fas fa-plus me-1"></i> Add Item
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle" id="itemsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:45%;">Product</th>
                                        <th class="text-end" style="width:15%;">Qty</th>
                                        <th class="text-end" style="width:15%;">Rate (Tk)</th>
                                        <th class="text-end" style="width:15%;">Total (Tk)</th>
                                        <th class="text-center" style="width:10%;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody">
                                    @foreach ($invoice->items as $idx => $item)
                                        <tr data-row="{{ $idx }}">
                                            <td>
                                                <input type="hidden" name="items[{{ $idx }}][product_id]" value="{{ $item->product_id }}" class="form-control form-control-sm product-id">
                                                <input type="hidden" name="items[{{ $idx }}][condition_state]" value="{{ $item->condition_state ?? 'Good' }}">
                                                <span class="fw-semibold">{{ $item->product?->product_name ?? 'Product #' . $item->product_id }}</span>
                                                @if ($item->product?->product_code)
                                                    <br><small class="text-muted">{{ $item->product->product_code }}</small>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                <input type="number" name="items[{{ $idx }}][qty]" value="{{ number_format((float) $item->qty, 4, '.', '') }}" step="0.0001" min="0.0001" class="form-control form-control-sm text-end item-qty" required>
                                            </td>
                                            <td class="text-end">
                                                <input type="number" name="items[{{ $idx }}][rate]" value="{{ number_format((float) $item->rate, 2, '.', '') }}" step="0.01" min="0" class="form-control form-control-sm text-end item-rate" required>
                                            </td>
                                            <td class="text-end fw-semibold line-total">0.00</td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-outline-danger btn-sm remove-row" title="Remove">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- Add item panel (hidden by default, toggled by Add Item button) --}}
                <div class="card border-0 shadow-sm mt-3 d-none" id="addPanel">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0"><i class="fas fa-search me-1" style="color:#7c3aed;"></i> Select Product to Add</h2>
                    </div>
                    <div class="card-body">
                        <select id="productSelect" class="form-select select2" style="width:100%;">
                            <option value="">— Search product by name or code —</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}"
                                        data-name="{{ $product->product_name }}"
                                        data-code="{{ $product->product_code ?? '' }}"
                                        data-rate="{{ number_format((float) ($product->sales_rate ?? 0), 2, '.', '') }}">
                                    {{ $product->product_name }}
                                    @if ($product->product_code) [{{ $product->product_code }}] @endif
                                </option>
                            @endforeach
                        </select>
                        <button type="button" class="btn btn-success mt-2" id="btnConfirmAdd">
                            <i class="fas fa-check me-1"></i> Add to Invoice
                        </button>
                    </div>
                </div>
            </div>

            {{-- Right column: invoice meta + totals --}}
            <div class="col-lg-4">
                {{-- Invoice details --}}
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1" style="color:#7c3aed;"></i> Invoice Details</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Invoice Date</label>
                            <input type="date" name="invoice_date" value="{{ $invoice->invoice_date instanceof \Carbon\Carbon ? $invoice->invoice_date->format('Y-m-d') : $invoice->invoice_date }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Sales Person (optional)</label>
                            <input type="text" name="sales_person" value="{{ old('sales_person', $invoice->sales_person ?? '') }}" class="form-control form-control-sm" maxlength="100">
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Discount (Tk)</label>
                                <input type="number" name="discount_amount" value="{{ number_format((float) $invoice->discount_amount, 2, '.', '') }}" step="0.01" min="0" class="form-control form-control-sm text-end" id="inputDiscount">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Transport (Tk)</label>
                                <input type="number" name="transport_cost" value="{{ number_format((float) $invoice->transport_cost, 2, '.', '') }}" step="0.01" min="0" class="form-control form-control-sm text-end" id="inputTransport">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Notes (optional)</label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2" maxlength="1000">{{ old('notes', $invoice->notes ?? '') }}</textarea>
                        </div>
                        <div class="form-check mb-0">
                            <input type="checkbox" name="is_soft_hold" value="1" class="form-check-input" id="chkSoftHold" {{ $invoice->is_soft_hold ? 'checked' : '' }}>
                            <label for="chkSoftHold" class="form-check-label small">Mark as soft-hold (awaiting godown)</label>
                        </div>
                    </div>
                </div>

                {{-- Totals --}}
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0"><i class="fas fa-calculator me-1" style="color:#7c3aed;"></i> Totals</h2>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Subtotal</span>
                            <span class="fw-semibold" id="sumSubtotal">0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Discount</span>
                            <span class="text-danger" id="sumDiscount">0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Transport</span>
                            <span class="text-success" id="sumTransport">0.00</span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold">Total</span>
                            <span class="fs-4 fw-bold text-primary" id="sumTotal">0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mt-1 small">
                            <span class="text-muted">Old Total</span>
                            <span class="text-muted" id="sumOldTotal">{{ number_format((float) $invoice->total_amount, 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">Change</span>
                            <span id="sumChange" class="fw-semibold">0.00</span>
                        </div>
                    </div>
                </div>

                {{-- Credit limit override --}}
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0"><i class="fas fa-shield-halved me-1" style="color:#7c3aed;"></i> Credit Limit</h2>
                    </div>
                    <div class="card-body">
                        <div class="form-check mb-2">
                            <input type="checkbox" name="credit_limit_override" value="1" class="form-check-input" id="chkOverride">
                            <label for="chkOverride" class="form-check-label small text-warning">Override credit limit (if exceeded)</label>
                        </div>
                        <input type="text" name="override_reason" class="form-control form-control-sm" maxlength="500" placeholder="Override reason (min 10 chars if overriding)" id="inputOverrideReason" disabled>
                    </div>
                </div>

                {{-- Submit --}}
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg" id="btnSave">
                        <i class="fas fa-save me-1"></i> Save Changes
                    </button>
                    <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-1"></i> Cancel
                    </a>
                </div>

                <div class="alert alert-info small mt-3 mb-0">
                    <i class="fas fa-circle-info me-1"></i>
                    Saving will reverse the old GL + customer ledger entry and post a new one. The invoice code stays the same.
                </div>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
(function () {
    var CSRF_TOKEN = window.CSRF_TOKEN;

    // -------- Money formatting --------
    function fmtMoney(v) {
        var n = parseFloat(v || 0);
        return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // -------- Recalculate all totals --------
    function recalcAll() {
        var subtotal = 0;
        $('#itemsBody tr').each(function () {
            var qty = parseFloat($(this).find('.item-qty').val()) || 0;
            var rate = parseFloat($(this).find('.item-rate').val()) || 0;
            var lineTotal = qty * rate;
            $(this).find('.line-total').text(fmtMoney(lineTotal));
            subtotal += lineTotal;
        });

        var discount = parseFloat($('#inputDiscount').val()) || 0;
        var transport = parseFloat($('#inputTransport').val()) || 0;
        var total = subtotal + transport - discount;

        $('#sumSubtotal').text(fmtMoney(subtotal));
        $('#sumDiscount').text(fmtMoney(discount));
        $('#sumTransport').text(fmtMoney(transport));
        $('#sumTotal').text(fmtMoney(total));

        var oldTotal = parseFloat('{{ (float) $invoice->total_amount }}');
        var change = total - oldTotal;
        var $change = $('#sumChange');
        $change.text((change >= 0 ? '+' : '') + fmtMoney(change));
        $change.removeClass('text-success text-danger');
        $change.addClass(change > 0 ? 'text-danger' : (change < 0 ? 'text-success' : 'text-muted'));
    }

    // -------- Re-index item rows after add/remove --------
    function reindexRows() {
        $('#itemsBody tr').each(function (idx) {
            $(this).attr('data-row', idx);
            $(this).find('.product-id').attr('name', 'items[' + idx + '][product_id]');
            $(this).find('input[name*="[qty]"]').attr('name', 'items[' + idx + '][qty]');
            $(this).find('input[name*="[rate]"]').attr('name', 'items[' + idx + '][rate]');
            $(this).find('input[name*="[condition_state]"]').attr('name', 'items[' + idx + '][condition_state]');
        });
        recalcAll();
    }

    // -------- Remove row --------
    $(document).on('click', '.remove-row', function () {
        if ($('#itemsBody tr').length <= 1) {
            alert('Cannot remove: at least one item is required.');
            return;
        }
        $(this).closest('tr').remove();
        reindexRows();
    });

    // -------- Live recalc on input --------
    $(document).on('input', '.item-qty, .item-rate', recalcAll);
    $(document).on('input', '#inputDiscount, #inputTransport', recalcAll);

    // -------- Toggle override reason --------
    $('#chkOverride').on('change', function () {
        $('#inputOverrideReason').prop('disabled', !this.checked);
        if (!this.checked) $('#inputOverrideReason').val('');
    });

    // -------- Add item panel toggle --------
    $('#btnAddItem').on('click', function () {
        $('#addPanel').toggleClass('d-none');
    });

    // -------- Confirm add product --------
    $('#btnConfirmAdd').on('click', function () {
        var $opt = $('#productSelect option:selected');
        if (!$opt.val()) {
            alert('Please select a product first.');
            return;
        }

        var productId = $opt.val();
        var name = $opt.data('name') || 'Product';
        var code = $opt.data('code') || '';
        var rate = $opt.data('rate') || 0;

        // Check if product already in the list.
        var exists = false;
        $('#itemsBody .product-id').each(function () {
            if ($(this).val() === productId) {
                exists = true;
                return false;
            }
        });
        if (exists) {
            alert('Product already in the invoice. Edit the qty/rate on the existing row instead.');
            return;
        }

        var idx = $('#itemsBody tr').length;
        var html =
            '<tr data-row="' + idx + '">' +
                '<td>' +
                    '<input type="hidden" name="items[' + idx + '][product_id]" value="' + productId + '" class="form-control form-control-sm product-id">' +
                    '<input type="hidden" name="items[' + idx + '][condition_state]" value="Good">' +
                    '<span class="fw-semibold">' + $('<div>').text(name).html() + '</span>' +
                    (code ? '<br><small class="text-muted">' + $('<div>').text(code).html() + '</small>' : '') +
                '</td>' +
                '<td class="text-end"><input type="number" name="items[' + idx + '][qty]" value="1" step="0.0001" min="0.0001" class="form-control form-control-sm text-end item-qty" required></td>' +
                '<td class="text-end"><input type="number" name="items[' + idx + '][rate]" value="' + rate + '" step="0.01" min="0" class="form-control form-control-sm text-end item-rate" required></td>' +
                '<td class="text-end fw-semibold line-total">0.00</td>' +
                '<td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm remove-row"><i class="fas fa-trash"></i></button></td>' +
            '</tr>';
        $('#itemsBody').append(html);
        recalcAll();

        // Reset the select.
        $('#productSelect').val('').trigger('change');
        $('#addPanel').addClass('d-none');
    });

    // -------- Submit handler (disable button to prevent double-submit) --------
    $('#editInvoiceForm').on('submit', function () {
        var $btn = $('#btnSave');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving…');
    });

    // -------- Initial calc --------
    recalcAll();
})();
</script>
@endpush
