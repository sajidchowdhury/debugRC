@extends('layouts.admin')

@push('css')
<link rel="stylesheet" href="/assets/css/purchase-index.css">
<link rel="stylesheet" href="/assets/css/purchase-order-form.css">
@endpush

@section('content')
@php
    $today    = old('po_date', now()->format('Y-m-d'));
    $oldExp   = old('expected_date', '');
    $oldSup   = old('supplier_id', '');
    $oldBr    = old('branch_id', auth()->user()?->branch_id);
    $oldWh    = old('warehouse_id', '');
    $oldDisc  = old('discount_amount', '0');
    $oldTax   = old('tax_amount', '0');
    $oldNotes = old('notes', '');
    $branchName = auth()->user()?->branch?->branch_name ?? 'Branch';

    // Pre-validate old('items') shape for JS seed.
    $seedItems = [];
    if (is_array(old('items'))) {
        foreach (old('items') as $row) {
            if (!is_array($row)) continue;
            $seedItems[] = [
                'product_id' => (int) ($row['product_id'] ?? 0),
                'product_name' => '', // unknown after redirect — user re-searches
                'product_code' => '',
                'qty'  => (float) ($row['qty']  ?? 0),
                'rate' => (float) ($row['rate'] ?? 0),
            ];
        }
    }
@endphp

<div class="purch-index-app purch-po-form-app container-fluid py-2">
    {{-- ─── Hero ──────────────────────────────────────────────────── --}}
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-file-invoice me-2"></i>New purchase order</h1>
            <p>Plan supplier purchase — stock and payable post when you receive on a GRN</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i>{{ e($branchName) }}</span>
        </div>
        <div class="purch-index-hero-actions">
            <a href="{{ route('admin.purchase-orders.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> List
            </a>
        </div>
    </header>

    <form id="poForm" method="POST" action="{{ route('admin.purchase-orders.store') }}">
        @csrf
        <input type="hidden" id="poFormMode" value="create">
        <input type="hidden" id="poId" value="">

        <div class="purch-po-form-layout">
            {{-- ─── Order details card ─────────────────────────────── --}}
            <section class="purch-po-form-card">
                <div class="purch-po-form-card-head"><i class="fas fa-info-circle me-1"></i> Order details</div>
                <div class="purch-po-form-card-body">
                    <div class="mb-3">
                        <label class="form-label">Supplier <span class="text-danger">*</span></label>
                        <select name="supplier_id" id="supplier_id" class="form-select" required>
                            <option value="">Select supplier</option>
                            @foreach ($suppliers as $s)
                                <option value="{{ $s->id }}"
                                    {{ (string) $oldSup === (string) $s->id ? 'selected' : '' }}>
                                    {{ e($s->supplier_name) }} ({{ e($s->supplier_code ?? '') }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Branch <span class="text-danger">*</span></label>
                        <select name="branch_id" id="branch_id" class="form-select" required>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}"
                                    {{ (string) $oldBr === (string) $b->id ? 'selected' : '' }}>
                                    {{ e($b->branch_name) }} ({{ e($b->branch_code ?? '') }})
                                </option>
                            @endforeach
                        </select>
                        @if (!auth()->user()?->hasRole('admin'))
                            <div class="form-text small">
                                <i class="fas fa-lock me-1"></i>You can only create POs for your own branch.
                            </div>
                        @endif
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Warehouse</label>
                        <select name="warehouse_id" id="warehouse_id" class="form-select">
                            <option value="">— not specified —</option>
                            @foreach ($warehouses as $w)
                                <option value="{{ $w->id }}"
                                    {{ (string) $oldWh === (string) $w->id ? 'selected' : '' }}>
                                    {{ e($w->warehouse_name) }}
                                    @if ($w->branch) · {{ e($w->branch->branch_name) }}@endif
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">PO date <span class="text-danger">*</span></label>
                            <input type="date" name="po_date" class="form-control" required
                                   value="{{ e($today) }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Expected date</label>
                            <input type="date" name="expected_date" class="form-control"
                                   value="{{ e($oldExp) }}">
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"
                                  placeholder="Notes for supplier or internal use">{{ e($oldNotes) }}</textarea>
                    </div>

                    <p class="small text-muted mb-0 mt-3">
                        <i class="fas fa-building me-1"></i> Branch: {{ e($branchName) }}
                        · Saved as <strong>draft</strong> until you receive goods on a GRN.
                    </p>
                </div>
            </section>

            {{-- ─── Line items card ────────────────────────────────── --}}
            <section class="purch-po-form-card purch-po-items-card">
                <div class="purch-po-form-card-head d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-boxes me-1"></i> Line items</span>
                    <button type="button" class="btn btn-sm btn-success" id="btnAddPoItem">
                        <i class="fas fa-plus me-1"></i> Add line
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="itemTable">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th style="width: 100px;">Qty</th>
                                <th style="width: 110px;">Rate</th>
                                <th class="text-end" style="width: 110px;">Amount</th>
                                <th style="width: 44px;"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <p class="small text-muted px-3 py-2 mb-0">
                    Search by product name or code · at least one line required
                </p>

                {{-- ─── Discount + Tax (Laravel-only additions vs legacy) ─── --}}
                <div class="purch-po-form-card-body border-top">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small mb-1">Discount amount</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">Tk</span>
                                <input type="number" name="discount_amount" id="discount_amount"
                                       class="form-control" step="0.01" min="0"
                                       value="{{ e($oldDisc) }}">
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-1">Tax amount</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">Tk</span>
                                <input type="number" name="tax_amount" id="tax_amount"
                                       class="form-control" step="0.01" min="0"
                                       value="{{ e($oldTax) }}">
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        {{-- ─── Footer (total + actions) ──────────────────────────── --}}
        <div class="purch-po-form-footer">
            <div class="purch-po-total-label">
                Total: <span id="totalAmount">0.00</span>
            </div>
            <div class="purch-po-form-actions">
                <a href="{{ route('admin.purchase-orders.index') }}" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-save me-1"></i> Save purchase order
                </button>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
window.PO_FORM_BOOT = {
    mode: 'create',
    searchUrl: @json(route('admin.purchase-orders.search-products')),
    csrf: @json(csrf_token()),
    seedItems: @json($seedItems),
};
</script>
<script>
(function () {
    var boot = window.PO_FORM_BOOT || {};
    var rowIndex = 0;
    var productSearchCache = {}; // rowId → { id, name, code }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function formatMoney(n) {
        return (parseFloat(n) || 0).toFixed(2);
    }

    // ── Row template (legacy-faithful) ───────────────────────────
    function buildProductRowHtml(rowId, opts) {
        opts = opts || {};
        var productName = opts.product_name || '';
        var productCode = opts.product_code || '';
        var productId   = opts.product_id   || '';
        var qty         = opts.qty          || '';
        var rate        = opts.rate         || '';
        var readonly    = !!(opts.readonly && productName);
        var displayVal  = readonly ? (productName + ' (' + productCode + ')') : '';

        return '' +
            '<td class="purch-po-product-cell">' +
                '<input type="text" class="form-control product-search" placeholder="Search product name or code…"' +
                    ' value="' + escapeHtml(displayVal) + '"' + (readonly ? ' readonly' : '') +
                    ' autocomplete="off" data-row-id="' + rowId + '">' +
                '<div class="purch-po-product-dropdown product-dropdown" id="dropdown-' + rowId + '"></div>' +
                '<input type="hidden" class="product-id-input" name="items[' + rowId + '][product_id]" value="' + escapeHtml(productId) + '">' +
            '</td>' +
            '<td>' +
                '<input type="number" class="form-control qty-input" name="items[' + rowId + '][qty]"' +
                    ' value="' + escapeHtml(qty) + '" step="0.01" min="0.01" placeholder="Qty" required>' +
            '</td>' +
            '<td>' +
                '<input type="number" class="form-control rate-input" name="items[' + rowId + '][rate]"' +
                    ' value="' + escapeHtml(rate) + '" step="0.01" min="0" placeholder="Rate" required>' +
            '</td>' +
            '<td class="text-end align-middle">' +
                '<strong class="row-amount">0.00</strong>' +
            '</td>' +
            '<td class="align-middle text-center">' +
                '<button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" title="Remove line">' +
                    '<i class="fas fa-trash"></i>' +
                '</button>' +
            '</td>';
    }

    function addItemRow(prefill) {
        var rowId = rowIndex++;
        var $tr = $('<tr id="item-row-' + rowId + '"></tr>');
        $tr.html(buildProductRowHtml(rowId, prefill));
        $('#itemTable tbody').append($tr);
        bindRowEvents($tr, rowId);
        if (prefill && prefill.product_name) {
            productSearchCache[rowId] = { id: prefill.product_id, name: prefill.product_name, code: prefill.product_code };
            calculateRowAmount(rowId);
        } else if (!prefill || !prefill.readonly) {
            $tr.find('.product-search').focus();
        }
        if (prefill && prefill.qty && prefill.rate) calculateRowAmount(rowId);
    }

    function bindRowEvents($tr, rowId) {
        var $search = $tr.find('.product-search');
        var $qty    = $tr.find('.qty-input');
        var $rate   = $tr.find('.rate-input');
        var $remove = $tr.find('.btn-remove-row');

        var _debounce = null;
        $search.on('input', function () {
            if (_debounce) clearTimeout(_debounce);
            _debounce = setTimeout(function () { searchProduct($search, rowId); }, 250);
        });
        $search.on('focus', function () {
            if ($('#dropdown-' + rowId).children().length) showProductDropdown(rowId);
        });
        $qty.on('input', function () { calculateRowAmount(rowId); });
        $rate.on('input', function () { calculateRowAmount(rowId); });
        $remove.on('click', function () { removeRow(rowId); });
    }

    function searchProduct($input, rowId) {
        var term = ($input.val() || '').trim();
        var $dropdown = $('#dropdown-' + rowId);
        if (!$dropdown.length) return;
        if (term.length < 1) { $dropdown.hide().empty(); return; }

        $.ajax({
            url: boot.searchUrl,
            method: 'GET',
            data: { term: term },
            dataType: 'json',
        }).done(function (rows) {
            if (!Array.isArray(rows)) rows = (rows && rows.data) ? rows.data : [];
            if (!rows.length) {
                $dropdown.html('<div class="p-2 text-muted small">No products found</div>');
                $dropdown.show();
                return;
            }
            var html = '';
            rows.forEach(function (p) {
                productSearchCache[rowId + ':' + p.id] = p;
                html += '<button type="button" class="dropdown-item"' +
                    ' data-row-id="' + rowId + '"' +
                    ' data-product-id="' + escapeHtml(p.id) + '"' +
                    ' data-product-name="' + escapeHtml(p.product_name) + '"' +
                    ' data-product-code="' + escapeHtml(p.product_code || '') + '">' +
                        '<strong>' + escapeHtml(p.product_name) + '</strong>' +
                        ' <span class="text-muted">(' + escapeHtml(p.product_code || '') + ')</span>' +
                    '</button>';
            });
            $dropdown.html(html).show();
        }).fail(function () {
            $dropdown.html('<div class="p-2 text-danger small">Search failed</div>').show();
        });
    }

    function showProductDropdown(rowId) {
        var $dd = $('#dropdown-' + rowId);
        if ($dd.children().length) $dd.show();
    }

    // Delegated click handler for dropdown items (bound once on body).
    $(document).off('click', '.purch-po-product-dropdown .dropdown-item')
               .on('click', '.purch-po-product-dropdown .dropdown-item', function () {
        var rowId       = $(this).data('row-id');
        var productId   = $(this).data('product-id');
        var productName = $(this).data('product-name');
        var productCode = $(this).data('product-code');
        selectProduct(rowId, productId, productName, productCode);
    });

    // Outside click → close all dropdowns.
    $(document).off('click.poProductDropdown')
               .on('click.poProductDropdown', function (e) {
        if (!$(e.target).closest('.product-search, .product-dropdown').length) {
            $('.product-dropdown').hide();
        }
    });

    function selectProduct(rowId, productId, productName, productCode) {
        var $row = $('#item-row-' + rowId);
        $row.find('.product-search').val(productName + ' (' + productCode + ')');
        $row.find('.product-id-input').val(productId);
        $('#dropdown-' + rowId).hide().empty();
        calculateRowAmount(rowId);
        $row.find('.qty-input').focus();
    }

    function calculateRowAmount(rowId) {
        var $row = $('#item-row-' + rowId);
        var qty  = parseFloat($row.find('.qty-input').val()) || 0;
        var rate = parseFloat($row.find('.rate-input').val()) || 0;
        $row.find('.row-amount').text(formatMoney(qty * rate));
        calculateTotalAmount();
    }

    function calculateTotalAmount() {
        var sum = 0;
        $('#itemTable tbody .row-amount').each(function () {
            sum += parseFloat($(this).text()) || 0;
        });
        var disc = parseFloat($('#discount_amount').val()) || 0;
        var tax  = parseFloat($('#tax_amount').val()) || 0;
        $('#totalAmount').text(formatMoney(Math.max(0, sum - disc + tax)));
    }

    function removeRow(rowId) {
        $('#item-row-' + rowId).remove();
        calculateTotalAmount();
        if ($('#itemTable tbody tr').length === 0) addItemRow();
    }

    // ── Init ──────────────────────────────────────────────────────
    $(function () {
        // Seed from old() (after redirect-with-input on validation error).
        if (boot.seedItems && boot.seedItems.length) {
            boot.seedItems.forEach(function (it) { addItemRow(it); });
        } else {
            addItemRow(); // one empty row to start
        }

        $('#btnAddPoItem').on('click', function () { addItemRow(); });
        $('#discount_amount, #tax_amount').on('input', calculateTotalAmount);

        // Submit guard — at least one valid line (product_id + qty + rate).
        $('#poForm').on('submit', function (e) {
            var valid = 0;
            $('#itemTable tbody tr').each(function () {
                var pid = parseInt($(this).find('.product-id-input').val(), 10);
                var qty = parseFloat($(this).find('.qty-input').val()) || 0;
                var rate = parseFloat($(this).find('.rate-input').val()) || 0;
                if (pid > 0 && qty > 0 && rate >= 0) valid++;
            });
            if (valid < 1) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'No valid line items',
                    text: 'Add at least one product with qty and rate before saving the PO.',
                });
                return false;
            }
            // Disable submit + show spinner.
            $('#submitBtn').prop('disabled', true)
                .html('<i class="fas fa-spinner fa-spin me-1"></i> Saving…');
        });

        calculateTotalAmount();
    });
})();
</script>
@endpush
@endsection
